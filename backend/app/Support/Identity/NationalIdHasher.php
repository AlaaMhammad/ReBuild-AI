<?php

declare(strict_types=1);

namespace App\Support\Identity;

use RuntimeException;

/**
 * Produces the deterministic, keyed digest stored in beneficiaries.national_id_hash.
 *
 * A plain SHA-256 of a 9-digit ID is trivially reversible by enumeration (10^9
 * candidates), so the digest is an HMAC under a dedicated secret that is kept
 * separate from APP_KEY: APP_KEY rotation must not invalidate the index.
 */
final class NationalIdHasher
{
    private const MINIMUM_KEY_BYTES = 32;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if (strlen($this->key) < self::MINIMUM_KEY_BYTES) {
            throw new RuntimeException(
                'NATIONAL_ID_HASH_KEY must be configured with at least '.self::MINIMUM_KEY_BYTES.' bytes of secret material.'
            );
        }
    }

    /**
     * Builds the hasher from configuration. A "base64:" prefix is decoded, matching APP_KEY conventions.
     */
    public static function fromConfig(?string $configured): self
    {
        $configured ??= '';

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('NATIONAL_ID_HASH_KEY has an invalid base64 payload.');
            }

            $configured = $decoded;
        }

        return new self($configured);
    }

    /**
     * @return non-empty-string 64-character lowercase hex digest
     */
    public function hash(string $nationalId): string
    {
        return hash_hmac('sha256', NationalId::normalize($nationalId), $this->key);
    }
}
