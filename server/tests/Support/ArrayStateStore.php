<?php

namespace Fleetbase\Solid\Tests\Support;

use Fleetbase\Solid\Auth\Contracts\OidcStateStore;

/**
 * An in-memory {@see OidcStateStore} for tests.
 *
 * Records the TTL each value was written with so that expiry can be asserted
 * without waiting, and exposes the raw entries so a test can check that a
 * single-use value was actually consumed.
 */
final class ArrayStateStore implements OidcStateStore
{
    /**
     * @var array<string, array{value: string, ttl: int}>
     */
    private array $entries = [];

    public function get(string $key): ?string
    {
        return $this->entries[$key]['value'] ?? null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        $this->entries[$key] = ['value' => $value, 'ttl' => $ttlSeconds];
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function ttl(string $key): ?int
    {
        return $this->entries[$key]['ttl'] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->entries);
    }
}
