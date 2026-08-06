<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/** Per-instance Redis connection state (PECL phpredis; #6098 / #20612 / #28099). */
final class RedisState
{
    /** @var resource|null */
    public $socket = null;

    public bool $connected = false;

    public string $host = '';

    public int $port = 6379;

    /** 0 = atomic, Redis::MULTI, or Redis::PIPELINE (#20612). */
    public int $mode = 0;

    /** Commands written but not yet read while in PIPELINE mode. */
    public int $pipelinePending = 0;

    /** Redis::OPT_SERIALIZER — SERIALIZER_NONE / SERIALIZER_PHP (#28099). */
    public int $serializer = RedisConstants::SERIALIZER_NONE;

    /** Redis::OPT_PREFIX (#28099). */
    public string $prefix = '';

    /** Redis::OPT_READ_TIMEOUT seconds (#28099). */
    public float $readTimeout = 0.0;

    /** Redis::OPT_TCP_KEEPALIVE (#28099). */
    public int $tcpKeepalive = 0;
}
