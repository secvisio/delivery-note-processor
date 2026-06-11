<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyAlias;
use App\Models\CompanyIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a raw, possibly noisy company name (as returned by the OCR→LLM step)
 * into a STABLE canonical filename slug.
 *
 * Pipeline (per the project spec):
 *
 *   raw name
 *     → PHP normalization (CompanyNameNormalizer)
 *     → MySQL candidate filtering via LIKE on significant tokens
 *     → PHP fuzzy scoring (levenshtein + similar_text) against candidates
 *     → strong match  : reuse the existing identity's rename_slug, learn alias
 *     → trustworthy    : create a new identity, return its rename_slug
 *     → otherwise      : return null  → caller renders `xxxxxx` / unknown folder
 *
 * MySQL is used ONLY to shrink the candidate set; the final similarity
 * decision always happens in PHP so behaviour is identical on any DB driver.
 */
class CompanyResolverService
{
    /** LLM certainty required to CREATE a brand-new canonical identity. */
    private const CONFIDENCE_THRESHOLD = 0.85;

    /** Fuzzy score at/above which a match is accepted unconditionally. */
    private const STRONG_MATCH = 0.92;

    /** Lower bound of the "needs corroboration" band. */
    private const WEAK_MATCH = 0.85;

    /** Minimum raw-name length for creating a new identity. */
    private const MIN_NAME_LENGTH = 5;

    /** Hard cap on candidate rows pulled back from the DB for scoring. */
    private const CANDIDATE_LIMIT = 50;

    /** levenshtein() refuses strings longer than 255 bytes. */
    private const LEVENSHTEIN_MAX = 255;

    private CompanyNameNormalizer $normalizer;

    public function __construct(?CompanyNameNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new CompanyNameNormalizer();
    }

    /**
     * Resolve a raw company name to a canonical filename slug, or null when
     * the company cannot be trusted/matched (caller treats null as unknown).
     *
     * @param string|null $rawName company name as returned by the LLM
     * @param float|null $confidence the LLM's self-reported certainty (0..1)
     * @param string $ocrText full OCR text, used as an evidence check
     */
    public function resolve(?string $rawName, ?float $confidence, string $ocrText): ?string
    {
        if ($rawName === null) {
            return null;
        }

        $rawName = trim($rawName);
        if ($rawName === '') {
            return null;
        }

        $normalized = $this->normalizer->normalize($rawName);
        if ($normalized === '') {
            return null;
        }

        $matchKey = $this->normalizer->stripLegalSuffixes($normalized);
        if ($matchKey === '') {
            $matchKey = $normalized;
        }

        $hasEvidence = $this->hasEvidenceInOcr($normalized, $ocrText);
        $confident = $confidence !== null && $confidence >= self::CONFIDENCE_THRESHOLD;

        // 1. + 2. + 3. — try to match an existing identity.
        $best = $this->bestMatch($matchKey, $this->findCandidates($matchKey, $normalized));

        if ($best !== null) {
            $identity = $best['identity'];
            $score = $best['score'];
            $exact = $best['exact'];

            $useMatch = $exact
                || $score >= self::STRONG_MATCH
                // 0.85–0.92 band: only with corroborating signals.
                || ($score >= self::WEAK_MATCH && $confident && $hasEvidence);

            if ($useMatch) {
                $this->learnAlias($identity, $rawName, $normalized, $exact ? 1.0 : $score, $exact ? 'exact' : 'fuzzy');
                $identity->forceFill(['last_seen_at' => Carbon::now()])->save();

                return $identity->rename_slug;
            }
        }

        // 4. — no usable match: create a new identity only if trustworthy.
        if ($this->canCreateIdentity($rawName, $normalized, $confident, $hasEvidence)) {
            $identity = $this->createIdentity($rawName, $normalized, $confidence);

            if ($identity !== null) {
                $this->learnAlias($identity, $rawName, $normalized, 1.0, 'created');

                return $identity->rename_slug;
            }
        }

        return null;
    }

    /**
     * MySQL candidate filtering. Pulls back identities whose canonical form OR
     * any alias matches at least one significant token of the input. This is a
     * coarse net — precision comes from the PHP scoring pass.
     *
     * @return Collection<int, CompanyIdentity>
     */
    private function findCandidates(string $matchKey, string $normalized): Collection
    {
        $tokens = $this->normalizer->significantTokens($matchKey);
        if ($tokens === []) {
            $tokens = $this->normalizer->significantTokens($normalized);
        }

        // No usable token to search on — fall back to an exact normalized hit.
        if ($tokens === []) {
            return CompanyIdentity::query()
                ->where('normalized_canonical', $normalized)
                ->limit(self::CANDIDATE_LIMIT)
                ->get();
        }

        $identityIds = CompanyIdentity::query()
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->orWhere('normalized_canonical', 'like', '%' . $token . '%');
                }
            })
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('id');

        $aliasIdentityIds = CompanyAlias::query()
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->orWhere('normalized_name', 'like', '%' . $token . '%');
                }
            })
            ->limit(self::CANDIDATE_LIMIT)
            ->pluck('company_identity_id');

        $ids = $identityIds->merge($aliasIdentityIds)->unique()->take(self::CANDIDATE_LIMIT);

        if ($ids->isEmpty()) {
            return collect();
        }

        return CompanyIdentity::with('aliases')->whereIn('id', $ids)->get();
    }

    /**
     * PHP fuzzy scoring. For each candidate, the input match key is compared
     * against the candidate's canonical match key AND every alias variant; the
     * candidate's score is the best of those. The global best across all
     * candidates wins.
     *
     * @param Collection<int, CompanyIdentity> $candidates
     * @return array{identity: CompanyIdentity, score: float, exact: bool}|null
     */
    private function bestMatch(string $matchKey, Collection $candidates): ?array
    {
        $best = null;

        foreach ($candidates as $identity) {
            $keys = [$this->normalizer->stripLegalSuffixes($identity->normalized_canonical)];

            foreach ($identity->aliases as $alias) {
                $keys[] = $this->normalizer->stripLegalSuffixes($alias->normalized_name);
            }

            foreach (array_unique(array_filter($keys)) as $candidateKey) {
                $exact = $candidateKey === $matchKey;
                $score = $exact ? 1.0 : $this->similarity($matchKey, $candidateKey);

                if ($best === null || $score > $best['score']) {
                    $best = ['identity' => $identity, 'score' => $score, 'exact' => $exact];
                }
            }
        }

        return $best;
    }

    /**
     * Combined similarity in [0,1], averaging a normalized levenshtein ratio
     * with similar_text's percentage. Both inputs are token-sorted first so
     * word reordering ("company 123 ux" vs "company ux 123") does not tank the
     * score. Long inputs skip levenshtein (its 255-byte limit) and use
     * similar_text alone.
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $a = $this->sortTokens($a);
        $b = $this->sortTokens($b);

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);
        $similarTextRatio = $percent / 100;

        if (strlen($a) > self::LEVENSHTEIN_MAX || strlen($b) > self::LEVENSHTEIN_MAX) {
            return $similarTextRatio;
        }

        $distance = levenshtein($a, $b);
        $maxLen = max(strlen($a), strlen($b));
        $levenshteinRatio = $maxLen > 0 ? 1 - ($distance / $maxLen) : 0.0;

        return ($similarTextRatio + $levenshteinRatio) / 2;
    }

    private function sortTokens(string $value): string
    {
        $tokens = array_filter(explode(' ', $value), static fn(string $t): bool => $t !== '');
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Whether the normalized company name has corroborating evidence in the
     * OCR text. The LLM is asked to extract from OCR, so a real name should
     * appear there; this blocks the LLM from inventing a canonical name. True
     * when the whole match key appears as a substring, or any significant
     * token (≥4 chars) is present in the normalized OCR text.
     */
    private function hasEvidenceInOcr(string $normalized, string $ocrText): bool
    {
        $normalizedOcr = $this->normalizer->normalize($ocrText);
        if ($normalizedOcr === '') {
            return false;
        }

        $matchKey = $this->normalizer->stripLegalSuffixes($normalized);
        if ($matchKey !== '' && str_contains($normalizedOcr, $matchKey)) {
            return true;
        }

        foreach ($this->normalizer->significantTokens($normalized) as $token) {
            if (strlen($token) >= 4 && str_contains($normalizedOcr, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creation gate (spec rule 4). All must hold:
     *   - raw name length ≥ 5
     *   - LLM confidence ≥ 0.85
     *   - evidence for the name exists in the OCR text
     *   - the normalized name is not generic junk (country, "kunde", ...)
     */
    private function canCreateIdentity(string $rawName, string $normalized, bool $confident, bool $hasEvidence): bool
    {
        if (mb_strlen($rawName) < self::MIN_NAME_LENGTH) {
            return false;
        }

        if (!$confident || !$hasEvidence) {
            return false;
        }

        if ($this->normalizer->isGeneric($normalized)) {
            return false;
        }

        // The canonical name must yield a non-empty filename slug.
        return FilenameNormalizer::sanitizeCompanyName($rawName) !== '';
    }

    private function createIdentity(string $rawName, string $normalized, ?float $confidence): ?CompanyIdentity
    {
        $slug = FilenameNormalizer::sanitizeCompanyName($rawName);
        if ($slug === '') {
            return null;
        }

        return CompanyIdentity::create([
            'canonical_name' => $rawName,
            'rename_slug' => $slug,
            'normalized_canonical' => $normalized,
            'created_from_raw_name' => $rawName,
            'first_seen_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'confidence_at_creation' => $confidence,
        ]);
    }

    /**
     * Record/refresh the alias the raw name was seen as. Idempotent on
     * (identity, normalized variant): repeats bump times_seen + last_seen_at.
     */
    private function learnAlias(
        CompanyIdentity $identity,
        string          $rawName,
        string          $normalized,
        float           $confidence,
        string          $source
    ): void
    {
        $alias = CompanyAlias::query()
            ->where('company_identity_id', $identity->id)
            ->where('normalized_name', $normalized)
            ->first();

        if ($alias === null) {
            CompanyAlias::create([
                'company_identity_id' => $identity->id,
                'raw_name' => $rawName,
                'normalized_name' => $normalized,
                'first_seen_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'times_seen' => 1,
                'match_confidence' => $confidence,
                'source' => $source,
            ]);

            return;
        }

        $alias->times_seen += 1;
        $alias->last_seen_at = Carbon::now();
        $alias->match_confidence = $confidence;
        $alias->save();
    }
}
