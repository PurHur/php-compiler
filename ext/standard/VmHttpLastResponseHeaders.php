<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Last HTTP stream-wrapper response headers (php-src ext/standard/basic_functions.c, issue #7236).
 *
 * Populated when {@see VmFs::fileGetContents()} completes an http fetch via {@see VmHttpFetchPure}.
 */
final class VmHttpLastResponseHeaders
{
    /** @var list<string>|null */
    private static ?array $headers = null;

    public static function isHttpUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    /**
     * @param list<string>|null $headers
     */
    public static function store(?array $headers): void
    {
        if (null === $headers || [] === $headers) {
            self::$headers = null;

            return;
        }
        self::$headers = $headers;
    }

    /**
     * @return list<string>|null
     */
    public static function get(): ?array
    {
        return self::$headers;
    }

    public static function clear(): void
    {
        self::$headers = null;
    }
}
