<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

/**
 * Memcached class constants subset (PECL php-memcached / libmemcached; #6099).
 *
 * Storage keys are lowercase (VmReflection::findClassConstantDecl).
 * Display names stay Zend casing via CLASS_CONSTANT_NAMES.
 */
final class MemcachedConstants
{
    /** @see libmemcached memcached_return_t */
    public const RES_SUCCESS = 0;
    public const RES_FAILURE = 1;
    public const RES_HOST_LOOKUP_FAILURE = 2;
    public const RES_CONNECTION_FAILURE = 3;
    public const RES_WRITE_FAILURE = 5;
    public const RES_READ_FAILURE = 6;
    public const RES_DATA_EXISTS = 12;
    public const RES_NOTSTORED = 14;
    public const RES_NOTFOUND = 16;
    public const RES_NO_SERVERS = 20;
    public const RES_TIMEOUT = 31;
    public const RES_BAD_KEY_PROVIDED = 32;

    /** PHP-specific options (php_memcached.h MEMC_OPT_*). */
    public const OPT_COMPRESSION = -1001;
    public const OPT_PREFIX_KEY = -1002;
    public const OPT_SERIALIZER = -1003;
    public const OPT_COMPRESSION_TYPE = -1004;
    public const OPT_USER_FLAGS = -1006;
    public const OPT_STORE_RETRY_COUNT = -1007;

    /** @see libmemcached MEMCACHED_BEHAVIOR_* (subset used by frameworks). */
    public const OPT_HASH = 2;
    public const OPT_DISTRIBUTION = 9;
    public const OPT_LIBKETAMA_COMPATIBLE = 16;
    public const OPT_BINARY_PROTOCOL = 18;
    public const OPT_CONNECT_TIMEOUT = 14;
    public const OPT_SEND_TIMEOUT = 12;
    public const OPT_RECV_TIMEOUT = 13;
    public const OPT_TCP_NODELAY = 10;
    public const OPT_NO_BLOCK = 6;
    public const OPT_TCP_KEEPALIVE = 32;

    public const SERIALIZER_PHP = 1;
    public const SERIALIZER_IGBINARY = 2;
    public const SERIALIZER_JSON = 3;
    public const COMPRESSION_FASTLZ = 1;
    public const COMPRESSION_ZLIB = 2;

    /** @var array<string, int> lowercase storage key => value */
    public const CLASS_CONSTANTS = [
        'res_success' => self::RES_SUCCESS,
        'res_failure' => self::RES_FAILURE,
        'res_host_lookup_failure' => self::RES_HOST_LOOKUP_FAILURE,
        'res_connection_failure' => self::RES_CONNECTION_FAILURE,
        'res_write_failure' => self::RES_WRITE_FAILURE,
        'res_read_failure' => self::RES_READ_FAILURE,
        'res_data_exists' => self::RES_DATA_EXISTS,
        'res_notstored' => self::RES_NOTSTORED,
        'res_notfound' => self::RES_NOTFOUND,
        'res_no_servers' => self::RES_NO_SERVERS,
        'res_timeout' => self::RES_TIMEOUT,
        'res_bad_key_provided' => self::RES_BAD_KEY_PROVIDED,
        'opt_compression' => self::OPT_COMPRESSION,
        'opt_prefix_key' => self::OPT_PREFIX_KEY,
        'opt_serializer' => self::OPT_SERIALIZER,
        'opt_compression_type' => self::OPT_COMPRESSION_TYPE,
        'opt_user_flags' => self::OPT_USER_FLAGS,
        'opt_store_retry_count' => self::OPT_STORE_RETRY_COUNT,
        'opt_hash' => self::OPT_HASH,
        'opt_distribution' => self::OPT_DISTRIBUTION,
        'opt_libketama_compatible' => self::OPT_LIBKETAMA_COMPATIBLE,
        'opt_binary_protocol' => self::OPT_BINARY_PROTOCOL,
        'opt_connect_timeout' => self::OPT_CONNECT_TIMEOUT,
        'opt_send_timeout' => self::OPT_SEND_TIMEOUT,
        'opt_recv_timeout' => self::OPT_RECV_TIMEOUT,
        'opt_tcp_nodelay' => self::OPT_TCP_NODELAY,
        'opt_no_block' => self::OPT_NO_BLOCK,
        'opt_tcp_keepalive' => self::OPT_TCP_KEEPALIVE,
        'serializer_php' => self::SERIALIZER_PHP,
        'serializer_igbinary' => self::SERIALIZER_IGBINARY,
        'serializer_json' => self::SERIALIZER_JSON,
        'compression_fastlz' => self::COMPRESSION_FASTLZ,
        'compression_zlib' => self::COMPRESSION_ZLIB,
    ];

    /** @var array<string, string> lowercase storage key => display name */
    public const CLASS_CONSTANT_NAMES = [
        'res_success' => 'RES_SUCCESS',
        'res_failure' => 'RES_FAILURE',
        'res_host_lookup_failure' => 'RES_HOST_LOOKUP_FAILURE',
        'res_connection_failure' => 'RES_CONNECTION_FAILURE',
        'res_write_failure' => 'RES_WRITE_FAILURE',
        'res_read_failure' => 'RES_READ_FAILURE',
        'res_data_exists' => 'RES_DATA_EXISTS',
        'res_notstored' => 'RES_NOTSTORED',
        'res_notfound' => 'RES_NOTFOUND',
        'res_no_servers' => 'RES_NO_SERVERS',
        'res_timeout' => 'RES_TIMEOUT',
        'res_bad_key_provided' => 'RES_BAD_KEY_PROVIDED',
        'opt_compression' => 'OPT_COMPRESSION',
        'opt_prefix_key' => 'OPT_PREFIX_KEY',
        'opt_serializer' => 'OPT_SERIALIZER',
        'opt_compression_type' => 'OPT_COMPRESSION_TYPE',
        'opt_user_flags' => 'OPT_USER_FLAGS',
        'opt_store_retry_count' => 'OPT_STORE_RETRY_COUNT',
        'opt_hash' => 'OPT_HASH',
        'opt_distribution' => 'OPT_DISTRIBUTION',
        'opt_libketama_compatible' => 'OPT_LIBKETAMA_COMPATIBLE',
        'opt_binary_protocol' => 'OPT_BINARY_PROTOCOL',
        'opt_connect_timeout' => 'OPT_CONNECT_TIMEOUT',
        'opt_send_timeout' => 'OPT_SEND_TIMEOUT',
        'opt_recv_timeout' => 'OPT_RECV_TIMEOUT',
        'opt_tcp_nodelay' => 'OPT_TCP_NODELAY',
        'opt_no_block' => 'OPT_NO_BLOCK',
        'opt_tcp_keepalive' => 'OPT_TCP_KEEPALIVE',
        'serializer_php' => 'SERIALIZER_PHP',
        'serializer_igbinary' => 'SERIALIZER_IGBINARY',
        'serializer_json' => 'SERIALIZER_JSON',
        'compression_fastlz' => 'COMPRESSION_FASTLZ',
        'compression_zlib' => 'COMPRESSION_ZLIB',
    ];
}
