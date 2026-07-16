<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/**
 * Redis class constants subset (PECL phpredis redis.c / redis.stub.php; #6098).
 */
final class RedisConstants
{
    public const OPT_SERIALIZER = 1;
    public const OPT_PREFIX = 2;
    public const OPT_READ_TIMEOUT = 3;
    public const OPT_TCP_KEEPALIVE = 6;
    public const SERIALIZER_NONE = 0;
    public const SERIALIZER_PHP = 1;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'OPT_SERIALIZER' => self::OPT_SERIALIZER,
        'OPT_PREFIX' => self::OPT_PREFIX,
        'OPT_READ_TIMEOUT' => self::OPT_READ_TIMEOUT,
        'OPT_TCP_KEEPALIVE' => self::OPT_TCP_KEEPALIVE,
        'SERIALIZER_NONE' => self::SERIALIZER_NONE,
        'SERIALIZER_PHP' => self::SERIALIZER_PHP,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'OPT_SERIALIZER' => 'OPT_SERIALIZER',
        'OPT_PREFIX' => 'OPT_PREFIX',
        'OPT_READ_TIMEOUT' => 'OPT_READ_TIMEOUT',
        'OPT_TCP_KEEPALIVE' => 'OPT_TCP_KEEPALIVE',
        'SERIALIZER_NONE' => 'SERIALIZER_NONE',
        'SERIALIZER_PHP' => 'SERIALIZER_PHP',
    ];
}
