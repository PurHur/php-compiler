<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\OutputRewriteVarsJitHelper;
use PHPCompiler\ext\standard\VmOutputRewriteVars;

/**
 * Request-scoped HTTP response state for VM scripts (issues #252, #311).
 *
 * Dev server and CGI drivers read this after script execution; reset per request.
 * Stored HTTP status follows php-src: 0 = unset; wire default is 200 (#6591).
 */
final class ResponseContext
{
    /** @var int 0 = unset (php-src SG(sapi_headers).http_response_code) */
    private static int $status = 0;

    /** @var list<string> */
    private static array $headers = [];

    /** When false (CLI), header() does not queue lines for headers_list() — php-src head.c SAPI gate (#4037). */
    private static bool $headerQueueEnabled = false;

    public static function reset(): void
    {
        self::$status = 0;
        self::$headers = [];
        OutputRewriteVarsJitHelper::reset();
        self::$headerQueueEnabled = false;
    }

    /** Enable pending-header tracking for CGI/dev-server requests (issue #4037). */
    public static function enableHeaderQueue(): void
    {
        self::$headerQueueEnabled = true;
    }

    /**
     * Mirror JIT {@see SuperglobalRefreshRuntime} — queue pending headers only when CGI env is present (#4037, #4110).
     */
    public static function syncHeaderQueueFromEnvironment(): void
    {
        $gateway = getenv('GATEWAY_INTERFACE');
        if (false !== $gateway && '' !== $gateway) {
            self::enableHeaderQueue();
        }
    }

    public static function isHeaderQueueEnabled(): bool
    {
        return self::$headerQueueEnabled;
    }

    public static function isHttpResponseCodeUnset(): bool
    {
        return 0 === self::$status;
    }

    /** Status for dev server / CGI when unset defaults to 200. */
    public static function getEffectiveStatus(): int
    {
        return 0 === self::$status ? 200 : self::$status;
    }

    /** Wire/default status (200 when unset). */
    public static function getStatus(): int
    {
        return self::getEffectiveStatus();
    }

    /**
     * http_response_code() getter — false when unset (ext/standard/head.c).
     *
     * @return int|false
     */
    public static function readHttpResponseCode()
    {
        return 0 === self::$status ? false : self::$status;
    }

    /**
     * http_response_code($code) — true on first set, prior int on later sets, false when invalid.
     *
     * @return true|int|false
     */
    public static function writeHttpResponseCode(int $code)
    {
        if ($code < 100 || $code > 599) {
            return false;
        }
        $previous = self::$status;
        self::$status = $code;

        return 0 === $previous ? true : $previous;
    }

    /**
     * False when $code is outside 100–599 (PHP semantics).
     */
    public static function setStatus(int $code): bool
    {
        if ($code < 100 || $code > 599) {
            return false;
        }
        self::$status = $code;

        return true;
    }

    /**
     * Reject header lines that embed CR/LF (response header injection, issue #77).
     */
    public static function assertSafeHeaderLine(string $line): void
    {
        if (preg_match('/[\r\n]/', $line)) {
            throw new \LogicException('header() values must not contain CR or LF characters');
        }
    }

    public static function addHeader(string $line, bool $replace = true): void
    {
        if ('' === $line) {
            return;
        }
        self::assertSafeHeaderLine($line);
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
            self::setStatus((int) $m[1]);
        }
        if (!self::$headerQueueEnabled) {
            return;
        }
        $name = self::headerNameFromLine($line);
        if ($replace && null !== $name) {
            self::removeHeader($name);
        }
        self::$headers[] = $line;
    }

    public static function removeHeader(?string $name = null): void
    {
        if (!self::$headerQueueEnabled) {
            return;
        }
        if (null === $name || '' === $name) {
            self::$headers = [];

            return;
        }
        $needle = strtolower($name);
        $kept = [];
        foreach (self::$headers as $line) {
            if (self::shouldKeepHeaderLine($line, $needle)) {
                $kept[] = $line;
            }
        }
        self::$headers = $kept;
    }

    private static function shouldKeepHeaderLine(string $line, string $needle): bool
    {
        $headerName = self::headerNameFromLine($line);

        return null === $headerName || strtolower($headerName) !== $needle;
    }

    /**
     * @return list<string>
     */
    public static function listHeaders(): array
    {
        return self::$headers;
    }

    private static function headerNameFromLine(string $line): ?string
    {
        if (preg_match('#^HTTP/#i', $line)) {
            return null;
        }
        $colon = strpos($line, ':');
        if (false === $colon) {
            return null;
        }

        return trim(substr($line, 0, $colon));
    }

    /** output_add_rewrite_var() — register mod_rewrite pair; same name replaces prior value (#6031). */
    public static function addRewriteVar(string $name, string $value): bool
    {
        OutputRewriteVarsJitHelper::add($name, $value);

        return true;
    }

    /** output_reset_rewrite_vars() — clear rewrite var table (#6031). */
    public static function resetRewriteVars(): bool
    {
        OutputRewriteVarsJitHelper::reset();

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function listRewriteVars(): array
    {
        return VmOutputRewriteVars::list();
    }
}
