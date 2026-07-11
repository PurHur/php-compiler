<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Built-in stream filter registry — php-src ext/standard/streams.c php_stream_get_filters().
 *
 * Matches Zend PHP 8.2 default filter list (no optional extensions).
 */
final class VmStreamFilters
{
    /** @var list<string> */
    private const BUILTIN_FILTERS = [
        'zlib.*',
        'string.rot13',
        'string.toupper',
        'string.tolower',
        'convert.*',
        'consumed',
        'dechunk',
        'convert.iconv.*',
    ];

    /** @var list<string> */
    private static array $registered = [];

    /** @var array<string, string> filter name => class name (#3283 stream_filter_register) */
    private static array $registeredClasses = [];

    public static function register(string $filterName, string $className): bool
    {
        $filterName = strtolower($filterName);
        if ('' === $filterName) {
            return false;
        }
        if (\in_array($filterName, self::$registered, true)) {
            return false;
        }
        self::$registered[] = $filterName;
        self::$registeredClasses[$filterName] = $className;

        return true;
    }

    public static function classForFilter(string $filterName): ?string
    {
        return self::$registeredClasses[strtolower($filterName)] ?? null;
    }

    public static function isUserFilterName(string $filterName): bool
    {
        $filterName = strtolower($filterName);

        return isset(self::$registeredClasses[$filterName]);
    }

    /**
     * @return list<string>
     */
    public static function registeredFilterNames(): array
    {
        return self::$registered;
    }

    public static function getFilters(): HashTable
    {
        $ht = new HashTable();
        foreach (self::allFilterNames() as $name) {
            $var = new Variable();
            $var->string($name);
            $ht->append($var);
        }

        return $ht;
    }

    /**
     * @return list<string>
     */
    public static function allFilterNames(): array
    {
        return \array_values(\array_unique(\array_merge(self::BUILTIN_FILTERS, self::$registered)));
    }
}
