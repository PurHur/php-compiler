<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/** Per-instance RedisCluster connection state (PECL phpredis; #28094). */
final class RedisClusterState
{
    /** @var resource|null */
    public $socket = null;

    public bool $connected = false;

    public string $host = '';

    public int $port = 6379;

    public float $timeout = 0.0;

    public float $readTimeout = 0.0;

    public bool $persistent = false;

    /** @var list<string> seed "host:port" strings */
    public array $seeds = [];

    public ?string $name = null;

    public ?string $lastError = null;
}
