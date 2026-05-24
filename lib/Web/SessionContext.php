<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Request-scoped session id for VM scripts (issue #1183).
 *
 * Dev server and CGI drivers reset this per request alongside {@see ResponseContext}.
 */
final class SessionContext
{
    private static string $id = '';

    public static function reset(): void
    {
        self::$id = '';
    }

    public static function get(): string
    {
        return self::$id;
    }

    public static function set(string $id): string
    {
        $previous = self::$id;
        self::$id = $id;

        return $previous;
    }
}
