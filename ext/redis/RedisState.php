<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/** Per-instance Redis connection state (PECL phpredis; #6098 / #20612 / #28099 / #28116). */
final class RedisState
{
    /** @var resource|null */
    public $socket = null;

    public bool $connected = false;

    public string $host = '';

    public int $port = 6379;

    /** Selected logical DB (SELECT; #28116). */
    public int $dbNum = 0;

    /** Connect/pconnect timeout seconds (#28116). */
    public float $timeout = 0.0;

    /** Persistent connection id when using pconnect; null otherwise (#28116). */
    public ?string $persistentId = null;

    /**
     * Auth credentials after successful AUTH (#28116).
     *
     * @var string|array{0: string, 1: string}|null
     */
    public $auth = null;

    /** Last Redis/protocol error string (#28116). */
    public ?string $lastError = null;

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
