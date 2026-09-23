<?php

namespace Fleetbase\Solid\Tests\Support;

use Fleetbase\Solid\Auth\Contracts\JwksResolver;

/**
 * A {@see JwksResolver} backed by fixed documents.
 *
 * Holds a "cached" document and, optionally, a different one to hand back when
 * `$forceRefresh` is set — which is how the key-rotation path is tested: the
 * token names a `kid` the cached copy does not have, and the verifier must
 * refetch before deciding.
 */
final class ArrayJwksResolver implements JwksResolver
{
    public int $resolveCount = 0;

    public int $refreshCount = 0;

    /**
     * @param array<string, mixed>      $cached
     * @param array<string, mixed>|null $afterRefresh
     */
    public function __construct(
        private readonly array $cached,
        private readonly ?array $afterRefresh = null,
    ) {
    }

    public function resolve(string $jwksUri, bool $forceRefresh = false): array
    {
        $this->resolveCount++;

        if ($forceRefresh) {
            $this->refreshCount++;

            return $this->afterRefresh ?? $this->cached;
        }

        return $this->cached;
    }
}
