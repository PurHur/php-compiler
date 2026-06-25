<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * JIT/AOT pending HTTP header queue static storage (#9545, php-in-PHP).
 *
 * VM SSOT remains {@see \PHPCompiler\Web\ResponseContext}; compiled modules use this helper.
 * php-src: ext/standard/head.c — sapi_header_op, header list
 */
final class PendingHeadersJitHelper
{
    private const MAX_HEADERS = 256;

    /** @var list<string> */
    private static array $headers = [];

    private static bool $queueEnabled = false;

    private static bool $flushed = false;

    public static function reset(): void
    {
        self::$headers = [];
        self::$queueEnabled = false;
        self::$flushed = false;
    }

    public static function enableHeaderQueue(): void
    {
        self::$queueEnabled = true;
    }

    public static function isFlushed(): int
    {
        return self::$flushed ? 1 : 0;
    }

    public static function addHeader(string $line, int $replace): void
    {
        if ('' === $line || preg_match('/[\r\n]/', $line)) {
            return;
        }
        self::maybeSetLocationStatus($line);
        if (!self::$queueEnabled) {
            return;
        }
        $name = self::headerNameFromLine($line);
        if (0 !== $replace && null !== $name) {
            self::removeHeader($name);
        }
        if (\count(self::$headers) >= self::MAX_HEADERS) {
            return;
        }
        self::$headers[] = $line;
    }

    public static function removeHeader(string $name): void
    {
        if (!self::$queueEnabled) {
            return;
        }
        if ('' === $name) {
            self::$headers = [];

            return;
        }
        $needle = strtolower($name);
        $kept = [];
        foreach (self::$headers as $line) {
            $headerName = self::headerNameFromLine($line);
            if (null === $headerName || strtolower($headerName) !== $needle) {
                $kept[] = $line;
            }
        }
        self::$headers = $kept;
    }

    /** @return HashTable|null null when header_list() should return [] (no CGI gateway) */
    public static function listHeadersTable(): ?HashTable
    {
        $gateway = getenv('GATEWAY_INTERFACE');
        if (false === $gateway || '' === $gateway) {
            return null;
        }

        return VmFs::stringListToArray(self::$headers);
    }

    public static function flushResponseHeaders(): void
    {
        if (self::$flushed) {
            return;
        }
        if (!self::isWebResponseEnvPresent()) {
            self::$flushed = true;

            return;
        }
        self::$flushed = true;
        $wrote = false;
        $status = HttpResponseJitHelper::getStatusRaw();
        if ($status >= 100 && $status <= 599) {
            fwrite(STDOUT, 'Status: '.$status."\r\n");
            $wrote = true;
        }
        foreach (self::$headers as $line) {
            fwrite(STDOUT, $line."\r\n");
            $wrote = true;
        }
        if ($wrote) {
            fwrite(STDOUT, "\r\n");
        }
    }

    public static function addSetcookie(
        string $name,
        string $value,
        int $expires,
        string $path,
        string $domain,
        int $secure,
        int $httponly,
        string $samesite,
        int $partitioned
    ): void {
        if ('' === $name) {
            return;
        }
        $line = SetcookieLine::build(
            $name,
            $value,
            $expires,
            $path,
            $domain,
            0 !== $secure,
            0 !== $httponly,
            $samesite,
            0 !== $partitioned
        );
        self::addHeader($line, 0);
    }

    private static function maybeSetLocationStatus(string $line): void
    {
        if (HttpResponseJitHelper::getStatusRaw() > 0) {
            return;
        }
        if (\strlen($line) < 9) {
            return;
        }
        if (0 === strncasecmp($line, 'Location:', 9)) {
            HttpResponseJitHelper::setStatusValidated(302);
        }
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
            HttpResponseJitHelper::setStatusValidated((int) $m[1]);
        }
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

    private static function isWebResponseEnvPresent(): bool
    {
        foreach (['REQUEST_METHOD', 'GATEWAY_INTERFACE', 'QUERY_STRING'] as $key) {
            $value = getenv($key);
            if (false !== $value && '' !== $value) {
                return true;
            }
        }

        return false;
    }
}
