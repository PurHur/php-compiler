<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostbynamel() DNS resolve for compiled JIT/AOT modules (#9382, php-in-PHP).
 *
 * Thin AOT NestedJIT cannot call {@see VmDns::resolveHostnameIpv4List} — the VmDns
 * closure pulls preg/UDP helpers and resolves as null (#3258). This helper uses a
 * self-contained /etc/hosts IPv4 path (php-src gethostbynamel core) for JIT/AOT;
 * VM execute still uses {@see VmDns} via gethostbynamel.php.
 *
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel)
 */
final class GethostbynamelJitHelper
{
    private const MAX_ADDRS = 64;

    public static function ipCount(string $hostname): int
    {
        return \count(self::resolveIpv4List($hostname));
    }

    public static function ipAt(string $hostname, int $index): string
    {
        $ips = self::resolveIpv4List($hostname);
        if (!isset($ips[$index])) {
            return '';
        }

        return $ips[$index];
    }

    /**
     * @return list<string>
     */
    private static function resolveIpv4List(string $hostname): array
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return [];
        }

        $ips = self::resolveViaEtcHosts($hostname);
        if ([] === $ips) {
            return [];
        }

        if ('localhost' === \strtolower($hostname)
            && 1 === \count($ips)
            && '127.0.0.1' === $ips[0]) {
            $ips[] = '127.0.0.1';
        }

        return $ips;
    }

    /**
     * @return list<string>
     */
    private static function resolveViaEtcHosts(string $hostname): array
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return [];
        }
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        $hostname = \strtolower($hostname);
        $stored = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = self::splitWhitespace($line);
            if (\count($parts) < 2) {
                continue;
            }
            $ip = $parts[0];
            if (!self::isIpv4Literal($ip)) {
                continue;
            }
            for ($i = 1, $n = \count($parts); $i < $n; ++$i) {
                if (\strtolower($parts[$i]) === $hostname) {
                    if (\count($stored) < self::MAX_ADDRS) {
                        $stored[] = $ip;
                    }
                    break;
                }
            }
        }

        return $stored;
    }

    /**
     * @return list<string>
     */
    private static function splitWhitespace(string $line): array
    {
        $parts = [];
        $len = \strlen($line);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && (' ' === $line[$i] || "\t" === $line[$i])) {
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $start = $i;
            while ($i < $len && ' ' !== $line[$i] && "\t" !== $line[$i]) {
                ++$i;
            }
            $parts[] = \substr($line, $start, $i - $start);
        }

        return $parts;
    }

    private static function isIpv4Literal(string $ip): bool
    {
        $octets = \explode('.', $ip);
        if (4 !== \count($octets)) {
            return false;
        }
        foreach ($octets as $octet) {
            if ('' === $octet || \strlen($octet) > 3) {
                return false;
            }
            if (!\ctype_digit($octet)) {
                return false;
            }
            $value = (int) $octet;
            if ($value < 0 || $value > 255) {
                return false;
            }
        }

        return true;
    }
}
