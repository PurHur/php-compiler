<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Registered hash algorithm names (ext/hash/hash.c php_hash_hashtable).
 *
 * php-src: {@see hash_hmac_algos} filters entries where hash->options & PHP_HASH_HMAC.
 * Snapshot matches Zend PHP 8.2 built-in ext/hash (issue #6365).
 */
final class HashAlgosRegistry
{
    /**
     * All digest names registered in php-src ext/hash (php_hash_hashtable).
     *
     * Snapshot matches Zend PHP 8.2 built-in ext/hash (issue #11463).
     *
     * @var list<string>
     */
    public const ALL_ALGOS = [
        'md2',
        'md4',
        'md5',
        'sha1',
        'sha224',
        'sha256',
        'sha384',
        'sha512/224',
        'sha512/256',
        'sha512',
        'sha3-224',
        'sha3-256',
        'sha3-384',
        'sha3-512',
        'ripemd128',
        'ripemd160',
        'ripemd256',
        'ripemd320',
        'whirlpool',
        'tiger128,3',
        'tiger160,3',
        'tiger192,3',
        'tiger128,4',
        'tiger160,4',
        'tiger192,4',
        'snefru',
        'snefru256',
        'gost',
        'gost-crypto',
        'adler32',
        'crc32',
        'crc32b',
        'crc32c',
        'fnv132',
        'fnv1a32',
        'fnv164',
        'fnv1a64',
        'joaat',
        'murmur3a',
        'murmur3c',
        'murmur3f',
        'xxh32',
        'xxh64',
        'xxh3',
        'xxh128',
        'haval128,3',
        'haval160,3',
        'haval192,3',
        'haval224,3',
        'haval256,3',
        'haval128,4',
        'haval160,4',
        'haval192,4',
        'haval224,4',
        'haval256,4',
        'haval128,5',
        'haval160,5',
        'haval192,5',
        'haval224,5',
        'haval256,5',
    ];

    /** @var list<string> */
    public const HMAC_ALGOS = [
        'md2',
        'md4',
        'md5',
        'sha1',
        'sha224',
        'sha256',
        'sha384',
        'sha512/224',
        'sha512/256',
        'sha512',
        'sha3-224',
        'sha3-256',
        'sha3-384',
        'sha3-512',
        'ripemd128',
        'ripemd160',
        'ripemd256',
        'ripemd320',
        'whirlpool',
        'tiger128,3',
        'tiger160,3',
        'tiger192,3',
        'tiger128,4',
        'tiger160,4',
        'tiger192,4',
        'snefru',
        'snefru256',
        'gost',
        'gost-crypto',
        'haval128,3',
        'haval160,3',
        'haval192,3',
        'haval224,3',
        'haval256,3',
        'haval128,4',
        'haval160,4',
        'haval192,4',
        'haval224,4',
        'haval256,4',
        'haval128,5',
        'haval160,5',
        'haval192,5',
        'haval224,5',
        'haval256,5',
    ];
}
