<?php
declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;

/**
 * Authoritative builder for the canonical delivery-note filename.
 *
 * Strict output contract:
 *
 *   ls_{id|xxxxxx}_{company|xxxxxx}[_{YmdHis}].{ext}
 *
 *  - The literal `ls_` prefix is non-negotiable. It is concatenated last,
 *    after segment sanitization, so no slug or limit pass can ever swallow
 *    it. This is the structural fix for the historical `s_<timestamp>.pdf`
 *    bug, which originated from a positional-format pipeline that ran the
 *    whole filename through Str::snake / Str::slug after assembly — letting
 *    casing and special characters in upstream tokens collapse the prefix.
 *
 *  - Missing or unusable segments are filled with the literal `xxxxxx`
 *    placeholder rather than dropped. This guarantees the four-segment
 *    shape (prefix, id, company, optional timestamp) regardless of what
 *    the upstream extractor returned.
 *
 *  - When EITHER the id or the company is missing, a YmdHis timestamp is
 *    appended so two unrecognized scans for the same partial input do not
 *    collide on disk. Callers should pass `timestamp` explicitly so tests
 *    stay deterministic; if absent, the wall clock is consulted as a last
 *    resort so production never produces an invalid filename.
 */
final class FileRenamingService
{
    public const PREFIX = 'ls';

    public const PRODUCTION_ORDER_PREFIX = 'PA';

    public const FRACHTBRIEF_PREFIX = 'FB';

    public const FALLBACK = FilenameNormalizer::FALLBACK_TOKEN;

    /**
     * @param array{
     *   delivery_note_id?: ?string,
     *   company_name?:     ?string,
     *   extension?:        ?string,
     *   timestamp?:        ?string,
     * } $data
     */
    public static function generate(array $data): string
    {
        $id = FilenameNormalizer::sanitizeIdentifier($data['delivery_note_id'] ?? null);
        $company = FilenameNormalizer::sanitizeCompanyName($data['company_name'] ?? null);
        $ext = FilenameNormalizer::sanitizeExtension($data['extension'] ?? null);

        $idSegment = $id !== '' ? $id : self::FALLBACK;
        $companySegment = $company !== '' ? $company : self::FALLBACK;

        $segments = [self::PREFIX, $idSegment, $companySegment];

        if ($id === '' || $company === '') {
            $segments[] = self::resolveTimestamp($data['timestamp'] ?? null);
        }

        return implode('_', $segments) . '.' . $ext;
    }

    /**
     * Build the canonical production-order filename:
     *
     *   PA_{auftrag|xxxxxx}_{produktion|xxxxxx}[_{YmdHis}].{ext}
     *
     * The timestamp suffix is appended ONLY when BOTH values are missing.
     * If a single value is present it acts as a unique identifier on its
     * own — matching the spec example `PA_xxxxxx_98765.pdf`.
     *
     * Reuses the same FilenameNormalizer rules used for delivery notes:
     * identifiers are lowercased and stripped of non-alphanumerics; the
     * extension is whitelist-restricted; the `PA_` prefix is concatenated
     * last so no sanitization pass can swallow it.
     *
     * @param array{
     *   auftragsnummer?: ?string,
     *   produktion?:     ?string,
     *   extension?:      ?string,
     *   timestamp?:      ?string,
     * } $data
     */
    public static function generateProductionOrder(array $data): string
    {
        $auftrag = FilenameNormalizer::sanitizeIdentifier($data['auftragsnummer'] ?? null);
        $produktion = FilenameNormalizer::sanitizeIdentifier($data['produktion'] ?? null);
        $ext = FilenameNormalizer::sanitizeExtension($data['extension'] ?? null);

        $auftragSegment = $auftrag !== '' ? $auftrag : self::FALLBACK;
        $produktionSegment = $produktion !== '' ? $produktion : self::FALLBACK;

        $segments = [self::PRODUCTION_ORDER_PREFIX, $auftragSegment, $produktionSegment];

        if ($auftrag === '' && $produktion === '') {
            $segments[] = self::resolveTimestamp($data['timestamp'] ?? null);
        }

        return implode('_', $segments) . '.' . $ext;
    }

    /**
     * Build the canonical Frachtbrief filename:
     *
     *   FB_{company|xxxxxx}_{pickupDate|xxxxxx}_{orderNumber|xxxxxx}[_{YmdHis}].{ext}
     *
     * Unlike the two methods above, the timestamp suffix is appended as soon
     * as ANY of the three segments falls back to the placeholder. A Frachtbrief
     * is filed by the combination of all three values, so a single missing one
     * already makes collisions between two scans plausible — and Flysystem's
     * copy() overwrites silently, so a collision would destroy the earlier file.
     *
     * Segment rules deliberately differ from the delivery-note/production-order
     * methods and must NOT be routed through sanitizeIdentifier():
     *  - the order number keeps its structural hyphen (`260630-01`)
     *  - the pickup date keeps its ISO hyphens (`2026-06-30`)
     * Both are validated by their dedicated FilenameNormalizer methods, which
     * are idempotent — passing already-normalized values through is safe.
     *
     * The company segment follows the existing project convention (lowercase
     * hyphenated slug), so it should be fed the canonical `rename_slug` coming
     * out of CompanyResolverService.
     *
     * The literal `FB_` prefix is concatenated last, after sanitization, for
     * the same reason as `ls_` and `PA_`: no slug or limit pass can swallow it.
     */
    public static function generateFrachtbrief(
        ?string $recipientCompany,
        ?string $pickupDate,
        ?string $orderNumber,
        ?string $extension,
        ?string $timestamp = null,
    ): string
    {
        $company = FilenameNormalizer::sanitizeCompanyName($recipientCompany);
        $date = FilenameNormalizer::sanitizePickupDate($pickupDate);
        $order = FilenameNormalizer::sanitizeOrderNumber($orderNumber);
        $ext = FilenameNormalizer::sanitizeExtension($extension);

        $companySegment = $company !== '' ? $company : self::FALLBACK;
        $dateSegment = $date !== '' ? $date : self::FALLBACK;
        $orderSegment = $order !== '' ? $order : self::FALLBACK;

        $segments = [self::FRACHTBRIEF_PREFIX, $companySegment, $dateSegment, $orderSegment];

        if ($company === '' || $date === '' || $order === '') {
            $segments[] = self::resolveTimestamp($timestamp);
        }

        return implode('_', $segments) . '.' . $ext;
    }

    /**
     * True when EITHER the id or the company is missing/unusable. Used by the
     * processor service to route the file to the unknown-review folder: a file
     * needs a human whenever at least one required value could not be
     * determined, even if the other one was extracted successfully.
     * Computed from the same sanitized inputs as generate(), so the routing
     * decision and the rendered filename never disagree.
     */
    public static function isFallback(array $data): bool
    {
        $id = FilenameNormalizer::sanitizeIdentifier($data['delivery_note_id'] ?? null);
        $company = FilenameNormalizer::sanitizeCompanyName($data['company_name'] ?? null);

        return $id === '' || $company === '';
    }

    private static function resolveTimestamp(?string $timestamp): string
    {
        if ($timestamp !== null && preg_match('/^\d{14}$/', $timestamp) === 1) {
            return $timestamp;
        }

        return Carbon::now()->format('YmdHis');
    }
}
