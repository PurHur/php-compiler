<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

/** Per-instance Memcached connection state (PECL php-memcached; #6099). */
final class MemcachedState
{
    /** @var list<array{host: string, port: int, weight: int}> */
    public array $servers = [];

    /** @var resource|null */
    public $socket = null;

    public string $connectedHost = '';

    public int $connectedPort = 0;

    public int $resultCode = MemcachedConstants::RES_SUCCESS;

    public string $prefixKey = '';
}
