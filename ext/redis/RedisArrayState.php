<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/**
 * Per-instance RedisArray host map (PECL phpredis; #28094).
 *
 * Minimal consistent-hash routing: crc32(key) % N picks a host socket.
 */
final class RedisArrayState
{
    /** @var list<array{host: string, port: int}> */
    public array $hosts = [];

    /** @var array<int, resource|null> index => socket */
    public array $sockets = [];

    public string $name = '';

    public ?string $lastError = null;
}
