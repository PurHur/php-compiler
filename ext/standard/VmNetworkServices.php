<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Network service/protocol lookups (issue #3650).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c
 */
final class VmNetworkServices
{
    private const PROTOCOLS_PATHS = [
        '/etc/protocols',
        __DIR__.'/data/protocols',
    ];

    private const SERVICES_PATHS = [
        '/etc/services',
        __DIR__.'/data/services',
    ];

    /** @return string|false */
    public static function getprotobynumber(int $number)
    {
        foreach (self::PROTOCOLS_PATHS as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $name = self::lookupProtocolNumber($path, $number);
            if (false !== $name) {
                return $name;
            }
        }

        return false;
    }

    /** @return string|false */
    public static function getservbyport(int $port, string $protocol)
    {
        if ('' === $protocol) {
            return false;
        }
        $proto = strtolower($protocol);
        foreach (self::SERVICES_PATHS as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $name = self::lookupServicePort($path, $port, $proto);
            if (false !== $name) {
                return $name;
            }
        }

        return false;
    }

    /** @return string|false */
    private static function lookupProtocolNumber(string $path, int $number)
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return false;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 3);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $name = $parts[0];
            if ((int) $parts[1] === $number) {
                return $name;
            }
        }

        return false;
    }

    /** @return string|false */
    private static function lookupServicePort(string $path, int $port, string $protocol)
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return false;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $name = $parts[0];
            if (!str_contains($parts[1], '/')) {
                continue;
            }
            [$svcPort, $svcProto] = explode('/', $parts[1], 2);
            if ((int) $svcPort === $port && strtolower($svcProto) === $protocol) {
                return $name;
            }
        }

        return false;
    }
}
