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
    private const MAX_SEGMENT_LENGTH = 80;

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];

    /**
     * Normalize a company name into a filename-safe segment.
     *
     * Rules:
     *  1. Unicode → ASCII (ä→a, ß→ss, é→e, ø→o).
     *  2. Lowercase.
     *  3. Remove `/` and `\` outright (no separator inserted) so that
     *     short legal-form tokens like "A/S" stay readable as "as" AND
     *     no path separator can ever leak through into a filename.
     *  4. Replace every other non-alphanumeric run with `_`.
     *  5. Collapse repeats, trim leading/trailing `_`.
     *  6. Hard-cap byte length so the final filename stays well under
     *     the 255-byte limit imposed by ext4/NTFS/SMB.
     */
    public static function sanitizeCompanyName(?string $name): string
    {
        $value = Str::ascii((string)$name);
        $value = Str::lower($value);
        $value = str_replace(['/', '\\'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim((string)$value, '_');

        return self::cap($value);
    }

    /**
     * Normalize a delivery-note or invoice id. Stricter than company-name
     * sanitization: the id must read as a single token, so non-alphanumeric
     * characters are stripped (not replaced with `_`).
     */
    public static function sanitizeIdentifier(?string $id): string
    {
        $value = Str::ascii((string)$id);
        $value = Str::lower($value);
        $value = preg_replace('/[^a-z0-9]+/', '', (string)$value);

        return self::cap((string)$value);
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

        return rtrim(substr($value, 0, self::MAX_SEGMENT_LENGTH), '_');
    }
}
