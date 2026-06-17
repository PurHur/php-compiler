<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP inet conversions when libc FFI is unavailable (#7929, #3225 phase 2).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 */
final class VmInetPure
{
    private const UINT32_MAX = 4294967295;

    public static function long2ip(int $proper_address): string|false
    {
        if ($proper_address < 0 || $proper_address > self::UINT32_MAX) {
            return false;
        }

        return \sprintf(
            '%d.%d.%d.%d',
            ($proper_address >> 24) & 0xFF,
            ($proper_address >> 16) & 0xFF,
            ($proper_address >> 8) & 0xFF,
            $proper_address & 0xFF
        );
    }

    public static function ip2long(string $ip): int|false
    {
        $octets = self::parseIpv4DottedQuadOctets($ip);
        if (null === $octets) {
            return false;
        }
        $long = 0;
        foreach ($octets as $octet) {
            $long = ($long << 8) | $octet;
        }

        return $long;
    }

    /**
     * php-src: php_ip2long() — dotted quad with no leading-zero octets (#9300).
     *
     * @return list<int>|null four octets 0..255 or null when invalid
     */
    public static function parseIpv4DottedQuadOctets(string $ip): ?array
    {
        if (!\preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $ip, $m)) {
            return null;
        }
        $octets = [];
        for ($i = 1; $i <= 4; ++$i) {
            $octet = (int) $m[$i];
            if ($octet > 255 || (string) $octet !== $m[$i]) {
                return null;
            }
            $octets[] = $octet;
        }

        return $octets;
    }

    public static function inet_ntop(string $in_addr): string|false
    {
        if ('' === $in_addr) {
            return false;
        }
        $len = VmString::byteLength($in_addr);
        if (4 === $len) {
            $unpacked = \unpack('N', $in_addr);
            if (false === $unpacked) {
                return false;
            }

            return self::long2ip((int) $unpacked[1]);
        }
        if (16 === $len) {
            return self::inet6Ntop($in_addr);
        }

        return false;
    }

    public static function inet_pton(string $address): string|false
    {
        if (str_contains($address, ':')) {
            return self::inet6Pton($address);
        }

        $long = self::ip2long($address);
        if (false === $long) {
            return false;
        }

        $packed = \pack('N', $long);

        return false === $packed ? false : $packed;
    }

    private static function inet6Pton(string $address): string|false
    {
        $address = \strtolower($address);
        if (str_contains($address, '.')) {
            if (!\preg_match('/^(.*:)(\d+\.\d+\.\d+\.\d+)$/', $address, $m)) {
                return false;
            }
            $v4 = self::ip2long($m[2]);
            if (false === $v4) {
                return false;
            }
            $address = $m[1].\sprintf('%x:%x', ($v4 >> 16) & 0xFFFF, $v4 & 0xFFFF);
        }

        $doubleColon = \substr_count($address, '::');
        if ($doubleColon > 1) {
            return false;
        }

        if (1 === $doubleColon) {
            [$head, $tail] = \explode('::', $address, 2);
            $headParts = '' === $head ? [] : \explode(':', $head);
            $tailParts = '' === $tail ? [] : \explode(':', $tail);
            $missing = 8 - \count($headParts) - \count($tailParts);
            if ($missing < 1) {
                return false;
            }
            $groups = \array_merge($headParts, \array_fill(0, $missing, '0'), $tailParts);
        } else {
            $groups = \explode(':', $address);
            if (8 !== \count($groups)) {
                return false;
            }
        }

        if (8 !== \count($groups)) {
            return false;
        }

        $bytes = '';
        foreach ($groups as $group) {
            if ('' === $group) {
                $group = '0';
            }
            if (!\preg_match('/^[0-9a-f]{1,4}$/', $group)) {
                return false;
            }
            $value = \hexdec($group);
            if ($value > 0xFFFF) {
                return false;
            }
            $packed = \pack('n', $value);
            if (false === $packed) {
                return false;
            }
            $bytes .= $packed;
        }

        return 16 === \strlen($bytes) ? $bytes : false;
    }

    private static function inet6Ntop(string $in_addr): string|false
    {
        if (16 !== \strlen($in_addr)) {
            return false;
        }
        $groups = [];
        for ($i = 0; $i < 16; $i += 2) {
            $unpacked = \unpack('n', $in_addr[$i].$in_addr[$i + 1]);
            if (false === $unpacked) {
                return false;
            }
            $groups[] = \dechex((int) $unpacked[1]);
        }

        $bestStart = -1;
        $bestLen = 0;
        $runStart = -1;
        $runLen = 0;
        foreach ($groups as $idx => $group) {
            if ('0' === $group) {
                if ($runStart < 0) {
                    $runStart = $idx;
                    $runLen = 1;
                } else {
                    ++$runLen;
                }
            } else {
                if ($runLen > $bestLen) {
                    $bestStart = $runStart;
                    $bestLen = $runLen;
                }
                $runStart = -1;
                $runLen = 0;
            }
        }
        if ($runLen > $bestLen) {
            $bestStart = $runStart;
            $bestLen = $runLen;
        }

        if ($bestLen > 1) {
            $head = \array_slice($groups, 0, $bestStart);
            $tail = \array_slice($groups, $bestStart + $bestLen);
            if ([] === $head && [] === $tail) {
                return '::';
            }
            if ([] === $head) {
                return '::'.\implode(':', $tail);
            }
            if ([] === $tail) {
                return \implode(':', $head).'::';
            }

            return \implode(':', $head).'::'.\implode(':', $tail);
        }

        return \implode(':', $groups);
    }
}
