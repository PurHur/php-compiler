<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * HTTP response status storage for compiled JIT/AOT modules (#9344, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\Web\ResponseContext} write/read semantics for JIT.
 * VM SSOT remains {@see VmHttpResponse} → ResponseContext.
 * php-src: ext/standard/head.c — php_http_response_code
 */
final class HttpResponseJitHelper
{
    private static int $status = 0;

    public static function reset(): void
    {
        self::$status = 0;
    }

    public static function getStatusRaw(): int
    {
        return self::$status;
    }

    public static function setStatusRaw(int $code): void
    {
        self::$status = $code;
    }

    public static function setStatusValidated(int $code): void
    {
        if ($code >= 100 && $code <= 599) {
            self::$status = $code;
        }
    }

    /**
     * http_response_code() getter — -1 when unset (maps to false), else 100–599.
     */
    public static function applyGetSentinel(): int
    {
        return 0 === self::$status ? -1 : self::$status;
    }

    /**
     * http_response_code($code) setter — -1 invalid/headers-sent (false), -2 first-set true, else previous status.
     * Code 0 triggers getter semantics (#9306). php-src accepts any other int (#12153).
     * Callers must refuse when headers already sent before invoking (#28929); AOT guards in HttpResponseRuntime.
     */
    public static function applySetLong(int $code): int
    {
        if (0 === $code) {
            return self::applyGetSentinel();
        }
        $previous = self::$status;
        self::$status = $code;

        return 0 === $previous ? -2 : $previous;
    }
}
