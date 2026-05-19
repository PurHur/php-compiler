<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Request-scoped HTTP response status for VM scripts (issue #252).
 *
 * Dev server and CGI drivers read this after script execution; reset per request.
 */
final class ResponseContext
{
    private static int $status = 200;

    public static function reset(): void
    {
        self::$status = 200;
    }

    public static function getStatus(): int
    {
        return self::$status;
    }

    /**
     * @return bool false when $code is outside 100–599 (PHP semantics)
     */
    public static function setStatus(int $code): bool
    {
        if ($code < 100 || $code > 599) {
            return false;
        }
        self::$status = $code;

        return true;
    }
}
