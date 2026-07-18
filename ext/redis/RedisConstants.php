<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/**
 * Redis class constants subset (PECL phpredis redis.c / redis.stub.php; #6098 / #20612).
 *
 * Storage keys are lowercase (VmReflection::findClassConstantDecl / ClassName::CONST).
 * Display names stay Zend casing via CLASS_CONSTANT_NAMES.
 */
final class RedisConstants
{
    public const OPT_SERIALIZER = 1;
    public const OPT_PREFIX = 2;
    public const OPT_READ_TIMEOUT = 3;
    public const OPT_TCP_KEEPALIVE = 6;
    public const SERIALIZER_NONE = 0;
    public const SERIALIZER_PHP = 1;
    /** @see phpredis php_redis.h MULTI */
    public const MULTI = 1;
    /** @see phpredis php_redis.h PIPELINE */
    public const PIPELINE = 2;

    /** @var array<string, int> lowercase storage key => value */
    public const CLASS_CONSTANTS = [
        'opt_serializer' => self::OPT_SERIALIZER,
        'opt_prefix' => self::OPT_PREFIX,
        'opt_read_timeout' => self::OPT_READ_TIMEOUT,
        'opt_tcp_keepalive' => self::OPT_TCP_KEEPALIVE,
        'serializer_none' => self::SERIALIZER_NONE,
        'serializer_php' => self::SERIALIZER_PHP,
        'multi' => self::MULTI,
        'pipeline' => self::PIPELINE,
    ];

    /** @var array<string, string> lowercase storage key => display name */
    public const CLASS_CONSTANT_NAMES = [
        'opt_serializer' => 'OPT_SERIALIZER',
        'opt_prefix' => 'OPT_PREFIX',
        'opt_read_timeout' => 'OPT_READ_TIMEOUT',
        'opt_tcp_keepalive' => 'OPT_TCP_KEEPALIVE',
        'serializer_none' => 'SERIALIZER_NONE',
        'serializer_php' => 'SERIALIZER_PHP',
        'multi' => 'MULTI',
        'pipeline' => 'PIPELINE',
    ];
}
