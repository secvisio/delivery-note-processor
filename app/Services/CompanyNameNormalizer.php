<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Stateless normalization used for COMPANY MATCHING (not for filenames).
 *
 * The filename-safe slug is still produced by FilenameNormalizer; this class
 * produces the looser "match key" against which OCR/LLM variants of the same
 * real company are compared. The contract is the usual one: same input →
 * same output, no clocks, no I/O.
 *
 * Two distinct outputs are exposed:
 *   - normalize():          the canonical lowercase/ascii-folded form. This is
 *                           what gets persisted (normalized_canonical /
 *                           normalized_name) so candidate LIKE lookups are
 *                           comparing like with like.
 *   - stripLegalSuffixes(): the same string with trailing legal forms
 *                           (gmbh, ag, ltd, ...) removed. Used ONLY for fuzzy
 *                           scoring so "ACME GmbH" and "ACME AG" do not look
 *                           artificially different — never for display.
 */
final class CompanyNameNormalizer
{
    /**
     * Tokens shorter than this are ignored when building LIKE candidate
     * filters and significance checks — they are too generic to narrow a
     * search and produce huge candidate sets.
     */
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * Legal company-form tokens removed before fuzzy scoring.
     */
    private const LEGAL_SUFFIXES = [
        'gmbh', 'mbh', 'ag', 'kg', 'kgaa', 'ohg', 'gbr', 'ug', 'ek', 'se',
        'co', 'kgmbh', 'gmbhcokg', 'ggmbh',
        'ltd', 'limited', 'llc', 'inc', 'incorporated', 'corp', 'corporation',
        'plc', 'bv', 'nv', 'sa', 'sarl', 'srl', 'spa', 'as', 'oy', 'ab',
    ];

    /**
     * Strings/tokens that are never a real company name. A normalized value
     * that equals one of these — or whose every significant token is one of
     * these — must never become a canonical identity.
     */
    private const GENERIC_TERMS = [
        // document vocabulary
        'lieferschein', 'lieferadresse', 'lieferant', 'rechnung', 'rechnungsadresse',
        'kunde', 'kundennummer', 'kundennr', 'bestellung', 'bestellnummer',
        'auftrag', 'auftragsnummer', 'produktion', 'seite', 'page', 'datum',
        'adresse', 'strasse', 'street', 'plz', 'ort', 'land', 'telefon', 'email',
        'invoice', 'delivery', 'note', 'customer', 'order', 'date',
        // country names (most common in this pipeline)
        'deutschland', 'germany', 'oesterreich', 'osterreich', 'austria',
        'schweiz', 'switzerland', 'france', 'frankreich', 'italy', 'italien',
        'spain', 'spanien', 'netherlands', 'niederlande', 'belgium', 'belgien',
        'poland', 'polen', 'denmark', 'daenemark',
    ];

    /**
     * Canonical lowercase/ascii-folded form. German umlauts are expanded
     * (ä→ae, ö→oe, ü→ue, ß→ss) BEFORE the generic ascii fold so they read the
     * German way rather than collapsing to a/o/u. Punctuation becomes
     * whitespace, runs of whitespace collapse to a single space.
     */
    public function normalize(?string $name): string
    {
        $value = (string)$name;

        // Expand German umlauts before the lossy ascii fold.
        $value = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
            ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
            $value
        );

        $value = Str::ascii($value);
        $value = Str::lower($value);

        // Drop everything that is not a letter, digit or space (strips OCR
        // noise like stray punctuation, pipes, bullets).
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        // Collapse whitespace and trim.
        $value = preg_replace('/\s+/', ' ', (string)$value);

        return trim((string)$value);
    }

    /**
     * `normalize()` with trailing/standalone legal-form tokens removed. Used
     * only as the fuzzy-comparison key, never persisted or displayed.
     */
    public function stripLegalSuffixes(string $normalized): string
    {
        if ($normalized === '') {
            return '';
        }

        $tokens = array_values(array_filter(
            explode(' ', $normalized),
            static fn(string $t): bool => $t !== '' && !in_array($t, self::LEGAL_SUFFIXES, true)
        ));

        return implode(' ', $tokens);
    }

    /**
     * Significant tokens for LIKE candidate filtering: distinct, at least
     * MIN_TOKEN_LENGTH long, and not a generic term. May be empty for inputs
     * made entirely of short/generic tokens (e.g. "co kg").
     *
     * @return array<int, string>
     */
    public function significantTokens(string $normalized): array
    {
        $tokens = array_filter(
            explode(' ', $normalized),
            fn(string $t): bool => strlen($t) >= self::MIN_TOKEN_LENGTH
                && !in_array($t, self::GENERIC_TERMS, true)
        );

        return array_values(array_unique($tokens));
    }

    /**
     * True when the normalized value is junk that must never become a
     * canonical company: empty, or every significant token is a generic term.
     */
    public function isGeneric(string $normalized): bool
    {
        if ($normalized === '') {
            return true;
        }

        $tokens = array_values(array_filter(explode(' ', $normalized), static fn(string $t): bool => $t !== ''));

        foreach ($tokens as $token) {
            // A single non-generic, non-trivial token is enough to be real.
            if (strlen($token) >= self::MIN_TOKEN_LENGTH && !in_array($token, self::GENERIC_TERMS, true)) {
                return false;
            }
        }

        return true;
    }
}
