<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Request-scoped HTTP response state for VM scripts (issues #252, #311).
 *
 * Dev server and CGI drivers read this after script execution; reset per request.
 */
final class ResponseContext
{
    private static int $status = 200;

    /** @var list<string> */
    private static array $headers = [];

    public static function reset(): void
    {
        self::$status = 200;
        self::$headers = [];
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
        self::$headers = array_values(array_filter(
            self::$headers,
            static function (string $line) use ($needle): bool {
                $headerName = self::headerNameFromLine($line);

                return null === $headerName || strtolower($headerName) !== $needle;
            }
        ));
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
