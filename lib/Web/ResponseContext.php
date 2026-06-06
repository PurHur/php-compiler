<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

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

    public static function reset(): void
    {
        self::$status = 0;
        self::$headers = [];
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
        self::assertSafeHeaderLine($line);
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
            self::setStatus((int) $m[1]);
        }
        $name = self::headerNameFromLine($line);
        if ($replace && null !== $name) {
            self::removeHeader($name);
        }
        self::$headers[] = $line;
    }

    public static function removeHeader(?string $name = null): void
    {
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
}
