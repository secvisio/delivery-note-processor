<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Pure, deterministic delivery-note filename builder.
 *
 *   id + company → ls_{id}_{company}.{ext}
 *   id only      → ls_{id}.{ext}
 *   company only → ls_{company}.{ext}
 *   neither      → ls_{fallback_token}.{ext}
 *
 * No clock, no randomness. Same $data → same string, byte-for-byte.
 *
 * The caller is responsible for ensuring `fallback_token` is itself
 * deterministic per-input — pass the source file hash, not now().
 */
final class FilenameGenerator
{
    /**
     * @param array{
     *   delivery_note_id?: ?string,
     *   company_name?:     ?string,
     *   extension?:        ?string,
     *   fallback_token?:   ?string,
     * } $data
     */
    public static function generate(array $data): string
    {
        $id = FilenameNormalizer::sanitizeIdentifier($data['delivery_note_id'] ?? null);
        $company = FilenameNormalizer::sanitizeCompanyName($data['company_name'] ?? null);
        $ext = FilenameNormalizer::sanitizeExtension($data['extension'] ?? null);

        $body = match (true) {
            $id !== '' && $company !== '' => "{$id}_{$company}",
            $id !== '' => $id,
            $company !== '' => $company,
            default => self::fallback($data['fallback_token'] ?? null),
        };

        return "ls_{$body}.{$ext}";
    }

    /**
     * Returns true when the given input would produce a fallback filename
     * (i.e. both the id and the company are missing/unusable). Used by the
     * processor service to decide whether to route a file to the unknown
     * folder. Computed from the same sanitized inputs as generate(), so
     * the two stay in lock-step.
     */
    public static function isFallback(array $data): bool
    {
        $id = FilenameNormalizer::sanitizeIdentifier($data['delivery_note_id'] ?? null);
        $company = FilenameNormalizer::sanitizeCompanyName($data['company_name'] ?? null);

        return $id === '' && $company === '';
    }

    private static function fallback(?string $token): string
    {
        $token = FilenameNormalizer::sanitizeIdentifier($token);

        return $token !== '' ? $token : 'unknown';
    }
}
