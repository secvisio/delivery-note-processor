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
