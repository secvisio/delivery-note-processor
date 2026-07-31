<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Stateless, deterministic normalization for filename segments.
 *
 * The contract: same input string → same output string, byte-for-byte,
 * across runs, processes, and machines. No clocks, no randomness, no I/O.
 */
final class FilenameNormalizer
{
    public const FALLBACK_TOKEN = 'xxxxxx';

    /**
     * Canonical prefix of a Rhenus WebOrder number. Every value returned by
     * sanitizeOrderNumber() that starts with this prefix IS a WebOrder number —
     * the method only ever emits `YYMMDD-NN` or this shape — which is what makes
     * a prefix check a safe carrier signal without a second validation regex.
     */
    public const WEBORDER_PREFIX = 'WOO';

    private const MAX_SEGMENT_LENGTH = 80;

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];

    /**
     * Normalize a company name into a filename-safe segment using a
     * URL-style slug (lowercase, hyphen-separated, ASCII-folded).
     *
     * Pre-processing replaces `/`, `\`, and `.` with spaces so abbreviations
     * like "A/S" round-trip as "a-s" instead of collapsing to "as", and so
     * a hostile `../../etc/passwd` cannot cross-contaminate adjacent tokens.
     * Everything else goes through Str::slug, which strips remaining
     * non-alphanumerics and trims edge separators.
     */
    public static function sanitizeCompanyName(?string $name): string
    {
        $value = (string)$name;
        $value = str_replace(['/', '\\', '.'], ' ', $value);
        $value = Str::slug($value, '-');

        return self::cap($value);
    }

    /**
     * Normalize a delivery-note or invoice id. Stricter than company-name
     * sanitization: the id must read as a single token, so non-alphanumeric
     * characters are stripped (not replaced with a separator).
     */
    public static function sanitizeIdentifier(?string $id): string
    {
        $value = Str::ascii((string)$id);
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', (string)$value);

        return self::cap((string)$value);
    }

    /**
     * Normalize a Frachtbrief order number. TWO shapes are accepted:
     *
     *  1. `YYMMDD-NN`   — the primary "Order Nummer" (e.g. `260630-01`)
     *  2. `WOO` + digits — the Rhenus "WebOrdernr." (e.g. `WOO0000006176714`)
     *
     * Deliberately NOT routed through sanitizeIdentifier(): that method strips
     * every non-alphanumeric character, which would destroy the structural
     * hyphen that makes the first shape recognizable (`260630-01` → `26063001`).
     *
     * Tolerated OCR noise:
     *  - surrounding/inner whitespace ("260630 - 01", "260630- 01", "260630 -01")
     *  - unicode dashes (en/em dash, minus sign) in place of the ASCII hyphen
     *  - a completely dropped hyphen ("26063001"), which is re-inserted
     *  - `O`/`0` confusion in the WebOrder prefix ("W000000006176714"), which is
     *    canonicalized back to the uppercase `WOO` prefix
     *
     * Anything that does not end up matching `\d{6}-\d{2}` or `WOO\d{8,20}`
     * exactly returns the empty string, which the caller renders as the
     * `xxxxxx` placeholder.
     *
     * The output is idempotent — the method is applied several times to the
     * same value during one processing run (evidence check, destination
     * resolution, filename generation), and all of them must agree.
     *
     * The digits are deliberately NOT interpreted, in NEITHER shape. The six
     * leading digits of `260630-01` often look like a YYMMDD date, and a
     * WebOrder number is nothing but digits, but no date meaning may be read
     * out of either: the digits are part of the order number and nothing else.
     * Do not add date extraction here — see resolveFrachtbriefPickupDate() in
     * DeliveryNoteProcessorService.
     */
    public static function sanitizeOrderNumber(?string $orderNumber): string
    {
        $value = (string)$orderNumber;

        // Unify the various dash characters OCR/LLM output onto ASCII '-'.
        $value = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $value);

        // Drop ALL whitespace, so spacing variants collapse onto one form.
        $value = (string)preg_replace('/\s+/u', '', $value);

        if (preg_match('/^(\d{6})-(\d{2})$/', $value, $matches) === 1) {
            return $matches[1] . '-' . $matches[2];
        }

        // Hyphen lost during OCR: 8 straight digits still carry the structure.
        if (preg_match('/^(\d{6})(\d{2})$/', $value, $matches) === 1) {
            return $matches[1] . '-' . $matches[2];
        }

        // Rhenus WebOrder number. The prefix is matched tolerantly (`W00`,
        // `WO0`, lowercase) but always rendered as the canonical `WOO`, so two
        // scans of the same document produce the same filename. The digit
        // count is bounded rather than fixed — only one production sample is
        // known so far, and a bare `WOO` followed by a couple of digits must
        // not pass as an identifier.
        if (preg_match('/^W[O0]{2}(\d{8,20})$/i', $value, $matches) === 1) {
            return self::WEBORDER_PREFIX . $matches[1];
        }

        return '';
    }

    /**
     * Normalize an Abholdatum into the canonical `YYYY-MM-DD` filename segment.
     *
     * Accepted inputs (the formats seen on these documents plus the ISO form
     * the agent is asked to emit):
     *   2026-06-30 / 2026.06.30 / 2026/06/30   (Y-m-d)
     *   30.06.2026 / 30/06/2026 / 30-06-2026   (d.m.Y)
     *
     * The day/month/year triple is validated with checkdate(), so impossible
     * dates such as `31.02.2026` are rejected rather than silently rolled over
     * (which is what a naive strtotime() would do). Returns '' when no real
     * date can be determined.
     */
    public static function sanitizePickupDate(?string $date): string
    {
        $value = trim((string)$date);
        if ($value === '') {
            return '';
        }

        // Year-first: 2026-06-30, 2026.06.30, 2026/06/30
        if (preg_match('#^(\d{4})[-./](\d{1,2})[-./](\d{1,2})$#', $value, $m) === 1) {
            return self::buildDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }

        // Day-first: 30.06.2026, 30/06/2026, 30-06-2026
        if (preg_match('#^(\d{1,2})[-./](\d{1,2})[-./](\d{4})$#', $value, $m) === 1) {
            return self::buildDate((int)$m[3], (int)$m[2], (int)$m[1]);
        }

        return '';
    }

    /**
     * Render a validated Y-m-d string, or '' when the triple is not a real
     * calendar date.
     */
    private static function buildDate(int $year, int $month, int $day): string
    {
        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Restrict the extension to a known whitelist. Anything unrecognized
     * (empty, mixed-case, '.php', '..', etc.) collapses to 'pdf' — the
     * dominant format in this pipeline — so a hostile or malformed source
     * filename can never smuggle in a different extension.
     */
    public static function sanitizeExtension(?string $extension): string
    {
        $extension = Str::lower((string)$extension);

        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'pdf';
    }

    private static function cap(string $value): string
    {
        if ($value === '' || strlen($value) <= self::MAX_SEGMENT_LENGTH) {
            return $value;
        }

        return rtrim(substr($value, 0, self::MAX_SEGMENT_LENGTH), '-');
    }
}
