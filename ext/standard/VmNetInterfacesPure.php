<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * net_get_interfaces() via /sys + /proc — no libc getifaddrs FFI (#8988, #23715).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces) / iface_append_unicast
 *
 * Linux: /sys/class/net flags+operstate, /proc/net/fib_trie (linear /32 host LOCAL),
 * /proc/net/route (LE hex), /proc/net/if_inet6.
 *
 * Intermediate flags/family are decimal strings; {@see VmNetInterfaces} reifies ints.
 */
final class VmNetInterfacesPure
{
    private const AF_INET = 2;

    private const AF_INET6 = 10;

    private const AF_PACKET = 17;

    private const IFF_UP = 0x1;

    private const IFF_BROADCAST = 0x2;

    private const IFF_RUNNING = 0x40;

    private const IFF_LOWER_UP = 0x10000;

    public static function available(): bool
    {
        return 'Linux' === \PHP_OS_FAMILY && \is_dir('/sys/class/net');
    }

    /**
     * @return array<string, array{up: bool, unicast: list<array<string, string>>}>|false
     */
    public static function collect(): array|false
    {
        if (!self::available()) {
            return false;
        }
        $names = VmDirNative::listSorted('/sys/class/net');
        if (false === $names) {
            return false;
        }
        // php-src walks getifaddrs — typically lo before others; listSorted is alpha (#28140)
        $names = self::orderIfaceNamesLikeGetifaddrs($names);
        $ipv4 = self::ipv4ByIface();
        $ipv6 = self::ipv6ByIface();
        $root = [];
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $flags = self::ifaFlags('/sys/class/net/'.$name.'/');
            $fs = (string) $flags;
            $unicast = [];
            $unicast[] = ['flags' => $fs, 'family' => (string) self::AF_PACKET];
            if (isset($ipv4[$name])) {
                foreach ($ipv4[$name] as $e) {
                    $row = [
                        'flags' => $fs,
                        'family' => (string) self::AF_INET,
                        'address' => $e['address'],
                        'netmask' => $e['netmask'],
                    ];
                    if (isset($e['broadcast']) && 0 !== ($flags & self::IFF_BROADCAST)) {
                        $row['broadcast'] = $e['broadcast'];
                    }
                    $unicast[] = $row;
                }
            }
            if (isset($ipv6[$name])) {
                foreach ($ipv6[$name] as $e) {
                    $unicast[] = [
                        'flags' => $fs,
                        'family' => (string) self::AF_INET6,
                        'address' => $e['address'],
                        'netmask' => $e['netmask'],
                    ];
                }
            }
            $root[$name] = [
                'up' => (0 !== ($flags & self::IFF_UP)),
                'unicast' => $unicast,
            ];
        }

        return [] === $root ? false : $root;
    }

    /**
     * Approximate getifaddrs() first-seen order without libc FFI (#28140, #8988).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function orderIfaceNamesLikeGetifaddrs(array $names): array
    {
        $lo = [];
        $rest = [];
        foreach ($names as $name) {
            if ('lo' === $name) {
                $lo[] = $name;
            } else {
                $rest[] = $name;
            }
        }

        return [...$lo, ...$rest];
    }

    private static function ifaFlags(string $base): int
    {
        $raw = self::rf($base.'flags');
        $flags = 0;
        if ('' !== $raw) {
            if (0 === \strncmp($raw, '0x', 2) || 0 === \strncmp($raw, '0X', 2)) {
                $flags = (int) \hexdec(\substr($raw, 2));
            } else {
                $flags = (int) $raw;
            }
        }
        $op = self::rf($base.'operstate');
        $c = self::rf($base.'carrier');
        if ('1' === $c || 'up' === $op || 'unknown' === $op) {
            $flags |= self::IFF_RUNNING | self::IFF_LOWER_UP;
        }

        return $flags;
    }

    /**
     * @return array<string, list<array{address: string, netmask: string, broadcast?: string}>>
     */
    private static function ipv4ByIface(): array
    {
        $raw = self::rf('/proc/net/fib_trie');
        $hosts = [];
        if ('' !== $raw) {
            $pos = \strpos($raw, "\nLocal:\n");
            if (false === $pos && 0 === \strncmp($raw, "Local:\n", 7)) {
                $pos = 0;
            }
            if (false !== $pos) {
                $sec = \substr($raw, $pos);
                if (\preg_match_all(
                    '/(?:\+--|\|--)\s+(\d+\.\d+\.\d+\.\d+)(?:\/\d+)?\s*\n\s+\/32\s+host\s+LOCAL\b/',
                    $sec,
                    $mm
                )) {
                    foreach ($mm[1] as $addr) {
                        $hosts[] = $addr;
                    }
                }
            }
        }
        if ([] === $hosts) {
            $hosts = ['127.0.0.1'];
        }
        $routes = self::routes();
        $by = [];
        foreach ($hosts as $addr) {
            $iface = self::ifaceOf($addr, $routes);
            if (null === $iface) {
                continue;
            }
            $prefix = self::prefixFor($addr, $routes);
            $mask = self::mask4($prefix);
            $entry = ['address' => $addr, 'netmask' => $mask];
            $ip = self::ip2long($addr);
            $m = self::ip2long($mask);
            if (-1 !== $ip && -1 !== $m && 0xFFFFFFFF !== $m) {
                $entry['broadcast'] = self::long2ip(($ip & $m) | ((~$m) & 0xFFFFFFFF));
            }
            if (!isset($by[$iface])) {
                $by[$iface] = [];
            }
            $by[$iface][] = $entry;
        }

        return $by;
    }

    /**
     * @return list<array{iface: string, network: int, mask: int}>
     */
    private static function routes(): array
    {
        $raw = self::rf('/proc/net/route');
        $out = [];
        $lines = \explode("\n", $raw);
        for ($i = 1, $n = \count($lines); $i < $n; ++$i) {
            $line = \trim($lines[$i]);
            if ('' === $line) {
                continue;
            }
            $cols = \preg_split('/\s+/', $line);
            if (!\is_array($cols) || \count($cols) < 8) {
                continue;
            }
            $dest = self::hexle($cols[1]);
            $mask = self::hexle($cols[7]);
            if (-1 === $dest || -1 === $mask || 0 === $mask) {
                continue;
            }
            $out[] = ['iface' => $cols[0], 'network' => $dest, 'mask' => $mask];
        }

        return $out;
    }

    private static function hexle(string $hex): int
    {
        if (8 !== \strlen($hex)) {
            return -1;
        }

        return \hexdec(\substr($hex, 0, 2))
            | (\hexdec(\substr($hex, 2, 2)) << 8)
            | (\hexdec(\substr($hex, 4, 2)) << 16)
            | (\hexdec(\substr($hex, 6, 2)) << 24);
    }

    /**
     * @param list<array{iface: string, network: int, mask: int}> $routes
     */
    private static function ifaceOf(string $addr, array $routes): ?string
    {
        $ip = self::ip2long($addr);
        if (-1 === $ip) {
            return null;
        }
        if (127 === (($ip >> 24) & 0xFF)) {
            return 'lo';
        }
        foreach ($routes as $r) {
            if (($ip & $r['mask']) === ($r['network'] & $r['mask'])) {
                return $r['iface'];
            }
        }

        return null;
    }

    /**
     * @param list<array{iface: string, network: int, mask: int}> $routes
     */
    private static function prefixFor(string $addr, array $routes): int
    {
        $ip = self::ip2long($addr);
        if (-1 === $ip) {
            return 32;
        }
        if (127 === (($ip >> 24) & 0xFF)) {
            return 8;
        }
        foreach ($routes as $r) {
            if (($ip & $r['mask']) === ($r['network'] & $r['mask'])) {
                $bits = 0;
                $x = $r['mask'] & 0xFFFFFFFF;
                while (0 !== $x) {
                    $bits += $x & 1;
                    $x >>= 1;
                }

                return $bits > 0 ? $bits : 32;
            }
        }

        return 32;
    }

    /**
     * @return array<string, list<array{address: string, netmask: string}>>
     */
    private static function ipv6ByIface(): array
    {
        $raw = self::rf('/proc/net/if_inet6');
        $by = [];
        foreach (\explode("\n", $raw) as $line) {
            $line = \trim($line);
            if ('' === $line) {
                continue;
            }
            $cols = \preg_split('/\s+/', $line);
            if (!\is_array($cols) || \count($cols) < 6) {
                continue;
            }
            $h = \strtolower($cols[0]);
            if (32 !== \strlen($h)) {
                continue;
            }
            $pfx = (int) \hexdec($cols[2]);
            $iface = $cols[5];
            if (!isset($by[$iface])) {
                $by[$iface] = [];
            }
            $by[$iface][] = ['address' => self::fmt6($h), 'netmask' => self::mask6($pfx)];
        }

        return $by;
    }

    private static function fmt6(string $h): string
    {
        $g = [];
        for ($i = 0; $i < 8; ++$i) {
            $x = \ltrim(\substr($h, $i * 4, 4), '0');
            $g[] = '' === $x ? '0' : $x;
        }
        $bs = -1;
        $bl = 0;
        $cs = -1;
        $cl = 0;
        for ($i = 0; $i < 8; ++$i) {
            if ('0' === $g[$i]) {
                if (-1 === $cs) {
                    $cs = $i;
                    $cl = 1;
                } else {
                    ++$cl;
                }
            } else {
                if ($cl > $bl) {
                    $bs = $cs;
                    $bl = $cl;
                }
                $cs = -1;
                $cl = 0;
            }
        }
        if ($cl > $bl) {
            $bs = $cs;
            $bl = $cl;
        }
        if ($bl < 2) {
            return \implode(':', $g);
        }
        $left = \array_slice($g, 0, $bs);
        $right = \array_slice($g, $bs + $bl);

        return ([] === $left ? '' : \implode(':', $left)).'::'.([] === $right ? '' : \implode(':', $right));
    }

    private static function mask6(int $p): string
    {
        if ($p < 0) {
            $p = 0;
        }
        if ($p > 128) {
            $p = 128;
        }
        $hex = '';
        for ($i = 0; $i < 16; ++$i) {
            $b = $p - ($i * 8);
            if ($b >= 8) {
                $hex .= 'ff';
            } elseif ($b > 0) {
                $hex .= \sprintf('%02x', (0xff << (8 - $b)) & 0xff);
            } else {
                $hex .= '00';
            }
        }

        return self::fmt6($hex);
    }

    private static function mask4(int $prefix): string
    {
        if ($prefix <= 0) {
            return '0.0.0.0';
        }
        if ($prefix >= 32) {
            return '255.255.255.255';
        }

        return self::long2ip((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    }

    private static function ip2long(string $ip): int
    {
        $p = \explode('.', $ip);
        if (4 !== \count($p)) {
            return -1;
        }
        $n = 0;
        foreach ($p as $o) {
            if ('' === $o || !\ctype_digit($o)) {
                return -1;
            }
            $v = (int) $o;
            if ($v < 0 || $v > 255) {
                return -1;
            }
            $n = (($n << 8) | $v) & 0xFFFFFFFF;
        }

        return $n;
    }

    private static function long2ip(int $n): string
    {
        $n &= 0xFFFFFFFF;

        return ((string) (($n >> 24) & 0xFF)).'.'
            .((string) (($n >> 16) & 0xFF)).'.'
            .((string) (($n >> 8) & 0xFF)).'.'
            .((string) ($n & 0xFF));
    }

    private static function rf(string $path): string
    {
        if (!\is_readable($path)) {
            return '';
        }
        $raw = @\file_get_contents($path);

        return \is_string($raw) ? \trim($raw) : '';
    }
}
