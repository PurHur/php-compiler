<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/** Per-instance Redis connection state (PECL phpredis; #6098). */
final class RedisState
{
    /** @var resource|null */
    public $socket = null;

    public bool $connected = false;

    public string $host = '';

    public int $port = 6379;
}
