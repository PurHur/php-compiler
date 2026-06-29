<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * DNS helpers for stdlib builtins (issue #3707, #5854, #7315).
 *
 * VM resolves via /etc/hosts + VmDnsUdpPure UDP transport (#12483, #12625).
 * JIT/AOT: lib/JIT/Builtin/GethostbynamelRuntime.php → GethostbynamelJitHelper PHP (#9382),
 * CheckdnsrrRuntime.php → CheckdnsrrJitHelper PHP (#9379).
 *
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel), PHP_FUNCTION(gethostbyaddr),
 * PHP_FUNCTION(gethostbyname), PHP_FUNCTION(checkdnsrr), PHP_FUNCTION(dns_check_record),
 * PHP_FUNCTION(dns_get_mx), PHP_FUNCTION(getmxrr), PHP_FUNCTION(dns_get_record)
 */
final class VmDns
{
    public const ERR_NONE = 0;

    public const ERR_INVALID_ADDRESS = 1;

    public const ERR_NOT_FOUND = 2;

    private const MAX_ADDRS = 64;

    /** DNS query class IN (arpa/nameser.h). */
    private const DNS_CLASS_IN = 1;

    /** @var array<string, int> php-src php_dns_record_types (ext/standard/dns.c) */
    private const DNS_RECORD_TYPES = [
        'A' => 1,
        'NS' => 2,
        'CNAME' => 5,
        'SOA' => 6,
        'PTR' => 12,
        'HINFO' => 13,
        'MX' => 15,
        'TXT' => 16,
        'AAAA' => 28,
        'SRV' => 33,
        'NAPTR' => 35,
        'A6' => 38,
        'ANY' => 255,
    ];

    /**
     * checkdnsrr() / dns_check_record() — whether DNS records of $type exist (#5983).
     *
     * php-src: ext/standard/dns.c — php_dns_check_record()
     */
    public static function checkdnsrr(string $hostname, string $type = 'MX'): bool
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return false;
        }
        $type = \strtoupper($type);
        $qtype = self::DNS_RECORD_TYPES[$type] ?? null;
        if (null === $qtype) {
            return false;
        }

        return self::checkdnsrrPurePhp($hostname, $qtype);
    }

    /**
     * @return HashTable|false
     */
    public static function gethostbynamel(string $hostname)
    {
        $ips = self::resolveHostnameIpv4List($hostname);
        if ([] === $ips) {
            return false;
        }

        return self::ipv4ListToIndexedHashTable($ips);
    }

    /**
     * IPv4 address strings for hostname (php-src gethostbynamel resolver core).
     *
     * @return list<string>
     */
    public static function resolveHostnameIpv4List(string $hostname): array
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return [];
        }

        $ips = self::resolveViaEtcHosts($hostname);
        if (null === $ips || [] === $ips) {
            $ips = self::resolveViaUdpA($hostname);
        }
        if (null === $ips || [] === $ips) {
            return [];
        }

        return self::finalizeIpv4ResolverList($hostname, $ips);
    }

    /**
     * glibc getaddrinfo returns duplicate A records for localhost on Linux (#12483).
     *
     * @param list<string> $ips
     *
     * @return list<string>
     */
    private static function finalizeIpv4ResolverList(string $hostname, array $ips): array
    {
        if ('localhost' === \strtolower($hostname)
            && 1 === \count($ips)
            && '127.0.0.1' === $ips[0]) {
            $ips[] = '127.0.0.1';
        }

        return $ips;
    }

    /**
     * @param list<string> $ips
     */
    public static function ipv4ListToIndexedHashTable(array $ips): HashTable
    {
        $ht = new HashTable();
        foreach ($ips as $index => $ip) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($ip);
            $ht->add((string) $index, $var);
        }

        return $ht;
    }

    /**
     * Forward DNS — first IPv4 for hostname (php-src gethostbyname parity, #7419).
     *
     * On lookup failure returns the original hostname (not false).
     */
    public static function gethostbyname(string $hostname): string
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return $hostname;
        }

        $list = self::gethostbynamel($hostname);
        if (false === $list) {
            return $hostname;
        }

        $first = $list->find('0');
        if (null === $first || Variable::TYPE_STRING !== $first->type) {
            return $hostname;
        }

        return $first->toString();
    }

    /**
     * Reverse DNS for IPv4 (php-src gethostbyaddr parity, #5854).
     *
     * @return string|false hostname on success
     */
    public static function gethostbyaddr(string $ipAddress, ?int &$error = null)
    {
        $error = self::ERR_NONE;
        if ('' === $ipAddress || \strlen($ipAddress) > 255) {
            $error = self::ERR_INVALID_ADDRESS;

            return false;
        }

        $name = self::resolveHostnameViaEtcHosts($ipAddress);
        if (null === $name || '' === $name) {
            $error = self::isValidIpv4Address($ipAddress)
                ? self::ERR_NOT_FOUND
                : self::ERR_INVALID_ADDRESS;

            return false;
        }

        return $name;
    }

    /**
     * dns_get_mx() / getmxrr() — MX host list + preference weights (#4125, #3662).
     *
     * @return array{hosts: list<string>, weights: list<int>}|false
     */
    public static function dnsGetMx(string $hostname)
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return false;
        }

        $packet = self::queryViaUdp($hostname, self::DNS_RECORD_TYPES['MX']);
        if (null === $packet) {
            return false;
        }

        $records = self::parseDnsMxRecords($packet);
        if ([] === $records) {
            return false;
        }

        $hosts = [];
        $weights = [];
        foreach ($records as $record) {
            $hosts[] = $record['host'];
            $weights[] = $record['weight'];
        }

        return ['hosts' => $hosts, 'weights' => $weights];
    }

    /** php-src DNS_* bitmask → wire qtype (ext/standard/dns.c php_dns_record_types). */
    private const DNS_TYPE_FLAGS = [
        0x00000001 => ['name' => 'A', 'qtype' => 1],
        0x00000002 => ['name' => 'NS', 'qtype' => 2],
        0x00000004 => ['name' => 'CNAME', 'qtype' => 5],
        0x00000008 => ['name' => 'SOA', 'qtype' => 6],
        0x00000010 => ['name' => 'PTR', 'qtype' => 12],
        0x00000020 => ['name' => 'HINFO', 'qtype' => 13],
        0x00000040 => ['name' => 'MX', 'qtype' => 15],
        0x00000080 => ['name' => 'TXT', 'qtype' => 16],
        0x00000100 => ['name' => 'AAAA', 'qtype' => 28],
        0x00000200 => ['name' => 'SRV', 'qtype' => 33],
        0x00000400 => ['name' => 'NAPTR', 'qtype' => 35],
        0x00000800 => ['name' => 'A6', 'qtype' => 38],
        0x00001000 => ['name' => 'ANY', 'qtype' => 255],
    ];

    private const DNS_VALID_TYPE_MASK = 0x00001FFF;

    /**
     * dns_get_record() — DNS record list for hostname (#6392).
     *
     * php-src: ext/standard/dns.c — php_dns_get_record()
     *
     * @return HashTable|false indexed list of associative record arrays
     */
    public static function dnsGetRecord(string $hostname, int $type = 1)
    {
        if (!self::isValidDnsHostname($hostname)) {
            return false;
        }
        self::validateDnsGetRecordType($type);

        $requested = self::expandDnsTypeBitmask($type);
        if ([] === $requested) {
            return false;
        }

        $records = [];
        foreach ($requested as $flag => $meta) {
            $chunk = match ($flag) {
                0x00000001 => self::collectARecords($hostname),
                0x00000040 => self::collectMxRecords($hostname),
                0x00000100 => self::collectAaaaRecords($hostname),
                default => [],
            };
            foreach ($chunk as $record) {
                $records[] = $record;
            }
        }

        if ([] === $records) {
            return new HashTable();
        }

        $ht = new HashTable();
        foreach ($records as $index => $record) {
            $slot = new Variable();
            $slot->array($record);
            $ht->addIndex($index, $slot);
        }

        return $ht;
    }

    /**
     * php-src ext/standard/dns.c — php_dns_check_hostname() (#13600).
     */
    public static function isValidDnsHostname(string $hostname): bool
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return false;
        }
        $hostname = \rtrim($hostname, '.');
        if ('' === $hostname) {
            return false;
        }
        foreach (\explode('.', $hostname) as $label) {
            if ('' === $label || \strlen($label) > 63) {
                return false;
            }
        }

        return true;
    }

    /** @throws \ValueError */
    public static function validateDnsGetRecordType(int $type): void
    {
        if ($type <= 0 || 0 !== ($type & ~self::DNS_VALID_TYPE_MASK)) {
            throw new \ValueError('dns_get_record(): Argument #2 ($type) must be a valid DNS record type');
        }
    }

    /**
     * @return array<int, array{name: string, qtype: int}>
     */
    private static function expandDnsTypeBitmask(int $type): array
    {
        if (0x00001000 & $type) {
            return self::DNS_TYPE_FLAGS;
        }

        $requested = [];
        foreach (self::DNS_TYPE_FLAGS as $flag => $meta) {
            if (0x00001000 === $flag) {
                continue;
            }
            if (0 !== ($type & $flag)) {
                $requested[$flag] = $meta;
            }
        }

        return $requested;
    }

    /**
     * Whether $hostname is a numeric IP literal (not a DNS name).
     *
     * php-src: ext/standard/dns.c — php_dns_get_record skips A queries for IP literals.
     */
    public static function isIpAddressLiteral(string $hostname): bool
    {
        if (self::isValidIpv4Address($hostname)) {
            return true;
        }

        return false !== \filter_var($hostname, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
    }

    /** @return list<HashTable> */
    private static function collectARecords(string $hostname): array
    {
        if (self::isIpAddressLiteral($hostname)) {
            return [];
        }

        $list = self::gethostbynamel($hostname);
        if (false === $list) {
            return [];
        }

        $records = [];
        $seen = [];
        foreach ($list->iterateKeyed(true) as $pair) {
            [, $ipVar] = $pair;
            $ipVar = $ipVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $ipVar->type) {
                continue;
            }
            $ip = $ipVar->toString();
            if (isset($seen[$ip])) {
                continue;
            }
            $seen[$ip] = true;
            $records[] = self::makeDnsRecord($hostname, 'A', ['ttl' => 0, 'ip' => $ip]);
        }

        return $records;
    }

    /** @return list<HashTable> */
    private static function collectAaaaRecords(string $hostname): array
    {
        $ips = self::resolveHostnameIpv6List($hostname);
        if ([] === $ips) {
            return [];
        }

        $records = [];
        $seen = [];
        foreach ($ips as $ip) {
            if (isset($seen[$ip])) {
                continue;
            }
            $seen[$ip] = true;
            $records[] = self::makeDnsRecord($hostname, 'AAAA', ['ttl' => 0, 'ipv6' => $ip]);
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    public static function resolveHostnameIpv6List(string $hostname): array
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return [];
        }

        $ips = self::resolveIpv6ViaEtcHosts($hostname);
        if (null === $ips || [] === $ips) {
            return [];
        }

        return $ips;
    }

    /** @return list<HashTable> */
    private static function collectMxRecords(string $hostname): array
    {
        $mx = self::dnsGetMx($hostname);
        if (false === $mx) {
            return [];
        }

        $records = [];
        foreach ($mx['hosts'] as $index => $target) {
            $records[] = self::makeDnsRecord($hostname, 'MX', [
                'ttl' => 0,
                'pri' => $mx['weights'][$index] ?? 0,
                'target' => $target,
            ]);
        }

        return $records;
    }

    /** @param array<string, int|string> $fields */
    private static function makeDnsRecord(string $hostname, string $typeName, array $fields): HashTable
    {
        $ht = new HashTable();
        self::addDnsStringField($ht, 'host', $hostname);
        self::addDnsStringField($ht, 'class', 'IN');
        self::addDnsStringField($ht, 'type', $typeName);
        foreach ($fields as $key => $value) {
            if (\is_int($value)) {
                self::addDnsIntField($ht, $key, $value);
            } else {
                self::addDnsStringField($ht, $key, (string) $value);
            }
        }

        return $ht;
    }

    private static function addDnsStringField(HashTable $ht, string $key, string $value): void
    {
        $slot = new Variable();
        $slot->string($value);
        $ht->add($key, $slot);
    }

    private static function addDnsIntField(HashTable $ht, string $key, int $value): void
    {
        $slot = new Variable();
        $slot->int($value);
        $ht->add($key, $slot);
    }

    public static function isValidIpv4Address(string $ip): bool
    {
        if (!\preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
            return false;
        }
        foreach (\explode('.', $ip) as $octet) {
            $n = (int) $octet;
            if ($n < 0 || $n > 255 || (string) $n !== $octet) {
                return false;
            }
        }

        return self::inetPtonIpv4($ip);
    }

    private static function inetPtonIpv4(string $ip): bool
    {
        if (!\preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
            return false;
        }
        foreach (\explode('.', $ip) as $octet) {
            $n = (int) $octet;
            if ($n < 0 || $n > 255 || (string) $n !== $octet) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string|null
     */
    private static function resolveHostnameViaEtcHosts(string $ip): ?string
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return null;
        }
        $lines = VmFs::file(
            $path,
            StdlibConstants::FILE_IGNORE_NEW_LINES | StdlibConstants::FILE_SKIP_EMPTY_LINES
        );
        if (false === $lines) {
            return null;
        }

        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            if ($parts[0] !== $ip) {
                continue;
            }
            for ($i = 1, $n = \count($parts); $i < $n; ++$i) {
                if ('#' !== $parts[$i][0]) {
                    return $parts[$i];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function resolveIpv6ViaEtcHosts(string $hostname): ?array
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return null;
        }
        $lines = VmFs::file(
            $path,
            StdlibConstants::FILE_IGNORE_NEW_LINES | StdlibConstants::FILE_SKIP_EMPTY_LINES
        );
        if (false === $lines) {
            return null;
        }

        $hostname = \strtolower($hostname);
        $stored = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $ip = $parts[0];
            if (!\str_contains($ip, ':')) {
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
     * Pure-PHP DNS check via /etc/hosts + UDP (#7934, #12428).
     */
    private static function checkdnsrrPurePhp(string $hostname, int $qtype): bool
    {
        if (1 === $qtype) {
            $ips = self::resolveViaEtcHosts($hostname);
            if (null !== $ips && [] !== $ips) {
                return true;
            }
        }

        $packet = self::queryViaUdp($hostname, $qtype);

        return null !== $packet && self::dnsResponseHasAnswers($packet);
    }

    /**
     * @return list<string>|null
     */
    private static function resolveViaUdpA(string $hostname): ?array
    {
        $packet = self::queryViaUdp($hostname, self::DNS_RECORD_TYPES['A']);
        if (null === $packet) {
            return null;
        }

        $ips = self::parseDnsIpv4Records($packet);

        return [] === $ips ? null : $ips;
    }

    /**
     * UDP DNS query via VmDnsUdpPure (#8937).
     *
     * @return string|null raw DNS response packet
     */
    private static function queryViaUdp(string $hostname, int $qtype): ?string
    {
        $nameservers = self::readResolvConfNameservers();
        if ([] === $nameservers) {
            return null;
        }

        $query = self::buildDnsQueryPacket($hostname, $qtype);
        foreach ($nameservers as $nameserver) {
            $response = self::udpDnsExchange($nameserver, $query);
            if (null !== $response && '' !== $response) {
                return $response;
            }
        }

        return null;
    }

    private static function dnsResponseHasAnswers(string $packet): bool
    {
        if (\strlen($packet) < 12) {
            return false;
        }

        return self::readUint16($packet, 6) > 0;
    }

    /**
     * @return list<string>
     */
    private static function parseDnsIpv4Records(string $packet): array
    {
        $len = \strlen($packet);
        if ($len < 12) {
            return [];
        }

        $qdcount = self::readUint16($packet, 4);
        $ancount = self::readUint16($packet, 6);
        $offset = 12;

        for ($i = 0; $i < $qdcount; ++$i) {
            $next = self::skipDnsName($packet, $len, $offset);
            if (null === $next) {
                return [];
            }
            $offset = $next + 4;
            if ($offset > $len) {
                return [];
            }
        }

        $ips = [];
        for ($i = 0; $i < $ancount; ++$i) {
            $parsed = self::readDnsName($packet, $len, $offset);
            if (null === $parsed) {
                break;
            }
            [$offset] = $parsed;
            if ($offset + 10 > $len) {
                break;
            }
            $type = self::readUint16($packet, $offset);
            $offset += 8;
            $rdlength = self::readUint16($packet, $offset);
            $offset += 2;
            if ($offset + $rdlength > $len) {
                break;
            }
            if (1 === $type && 4 === $rdlength) {
                $ips[] = \inet_ntop(\substr($packet, $offset, 4)) ?: '';
            }
            $offset += $rdlength;
        }

        return \array_values(\array_filter($ips, static fn (string $ip): bool => '' !== $ip));
    }

    private static function buildDnsQueryPacket(string $hostname, int $qtype): string
    {
        $id = \random_int(0, 0xFFFF);
        $header = \pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);
        $question = self::encodeDnsName($hostname).\pack('nn', $qtype, self::DNS_CLASS_IN);

        return $header.$question;
    }

    private static function encodeDnsName(string $hostname): string
    {
        $hostname = \rtrim($hostname, '.');
        $encoded = '';
        foreach (\explode('.', $hostname) as $label) {
            $len = \strlen($label);
            if ($len > 63) {
                $label = \substr($label, 0, 63);
                $len = 63;
            }
            $encoded .= \chr($len).$label;
        }

        return $encoded."\0";
    }

    /**
     * @return list<string>
     */
    private static function readResolvConfNameservers(): array
    {
        $path = '/etc/resolv.conf';
        if (!\is_readable($path)) {
            return [];
        }
        $lines = VmFs::file(
            $path,
            StdlibConstants::FILE_IGNORE_NEW_LINES | StdlibConstants::FILE_SKIP_EMPTY_LINES
        );
        if (false === $lines) {
            return [];
        }

        $servers = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            if (!\str_starts_with($line, 'nameserver')) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $ip = $parts[1];
            if ('' !== $ip && !\in_array($ip, $servers, true)) {
                $servers[] = $ip;
            }
        }

        return $servers;
    }

    private static function udpDnsExchange(string $nameserver, string $query): ?string
    {
        return VmDnsUdpNative::exchange($nameserver, $query);
    }

    /**
     * @return list<array{host: string, weight: int}>
     */
    private static function parseDnsMxRecords(string $packet): array
    {
        $len = \strlen($packet);
        if ($len < 12) {
            return [];
        }

        $qdcount = self::readUint16($packet, 4);
        $ancount = self::readUint16($packet, 6);
        $offset = 12;

        for ($i = 0; $i < $qdcount; ++$i) {
            $next = self::skipDnsName($packet, $len, $offset);
            if (null === $next) {
                return [];
            }
            $offset = $next + 4;
            if ($offset > $len) {
                return [];
            }
        }

        $mx = [];
        for ($i = 0; $i < $ancount; ++$i) {
            $parsed = self::readDnsName($packet, $len, $offset);
            if (null === $parsed) {
                break;
            }
            [$offset] = $parsed;
            if ($offset + 10 > $len) {
                break;
            }
            $type = self::readUint16($packet, $offset);
            $offset += 8;
            $rdlength = self::readUint16($packet, $offset);
            $offset += 2;
            if ($offset + $rdlength > $len) {
                break;
            }
            if (15 === $type && $rdlength >= 2) {
                $weight = self::readUint16($packet, $offset);
                $exchange = self::readDnsName($packet, $len, $offset + 2);
                if (null !== $exchange) {
                    $mx[] = ['host' => $exchange[1], 'weight' => $weight];
                }
            }
            $offset += $rdlength;
        }

        \usort($mx, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return $mx;
    }

    private static function readUint16(string $packet, int $offset): int
    {
        return (\ord($packet[$offset]) << 8) | \ord($packet[$offset + 1]);
    }

    private static function skipDnsName(string $packet, int $len, int $offset): ?int
    {
        $parsed = self::readDnsName($packet, $len, $offset);

        return null === $parsed ? null : $parsed[0];
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private static function readDnsName(string $packet, int $len, int $offset, int $depth = 0): ?array
    {
        if ($depth > 16 || $offset >= $len) {
            return null;
        }

        $labels = [];
        $endOffset = $offset;
        $jumped = false;

        while ($offset < $len) {
            $labellen = \ord($packet[$offset]);
            if (0 === $labellen) {
                $offset++;
                if (!$jumped) {
                    $endOffset = $offset;
                }

                break;
            }
            if (($labellen & 0xC0) === 0xC0) {
                if ($offset + 1 >= $len) {
                    return null;
                }
                $ptr = (($labellen & 0x3F) << 8) | \ord($packet[$offset + 1]);
                if (!$jumped) {
                    $endOffset = $offset + 2;
                }
                $target = self::readDnsName($packet, $len, $ptr, $depth + 1);
                if (null === $target) {
                    return null;
                }
                if ('' !== $target[1]) {
                    $labels[] = $target[1];
                }
                $offset = $endOffset;

                break;
            }
            if ($labellen > 63) {
                return null;
            }
            $offset++;
            if ($offset + $labellen > $len) {
                return null;
            }
            $labels[] = \substr($packet, $offset, $labellen);
            $offset += $labellen;
        }

        return [$offset, \implode('.', $labels)];
    }

    /**
     * @return list<string>|null
     */
    private static function resolveViaEtcHosts(string $hostname): ?array
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return null;
        }
        $lines = VmFs::file(
            $path,
            StdlibConstants::FILE_IGNORE_NEW_LINES | StdlibConstants::FILE_SKIP_EMPTY_LINES
        );
        if (false === $lines) {
            return null;
        }

        $hostname = \strtolower($hostname);
        $stored = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if (null === $parts || \count($parts) < 2) {
                continue;
            }
            $ip = $parts[0];
            if (!\preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
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
}
