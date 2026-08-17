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
        if (null !== $name && '' !== $name) {
            return $name;
        }

        if (self::isValidIpv4Address($ipAddress)) {
            $arpa = self::ipv4ToInAddrArpa($ipAddress);
            if (null !== $arpa) {
                $ptr = self::resolveViaUdpPtr($arpa);
                if (null !== $ptr && '' !== $ptr) {
                    return $ptr;
                }
            }
            $error = self::ERR_NOT_FOUND;

            return false;
        }

        $error = self::ERR_INVALID_ADDRESS;

        return false;
    }

    /**
     * Build in-addr.arpa query name for IPv4 reverse DNS (php-src dns.c).
     */
    public static function ipv4ToInAddrArpa(string $ip): ?string
    {
        if (!self::isValidIpv4Address($ip)) {
            return null;
        }
        $octets = \explode('.', $ip);
        if (4 !== \count($octets)) {
            return null;
        }

        return \implode('.', \array_reverse($octets)).'.in-addr.arpa';
    }

    private static function resolveViaUdpPtr(string $arpaName): ?string
    {
        $packet = self::queryViaUdp($arpaName, self::DNS_RECORD_TYPES['PTR']);
        if (null === $packet) {
            return null;
        }

        return self::parseDnsPtrRecord($packet);
    }

    /**
     * @return string|null first PTR target hostname
     */
    public static function parseDnsPtrRecord(string $packet): ?string
    {
        $len = \strlen($packet);
        if ($len < 12) {
            return null;
        }

        $qdcount = self::readUint16($packet, 4);
        $ancount = self::readUint16($packet, 6);
        $offset = 12;

        for ($i = 0; $i < $qdcount; ++$i) {
            $next = self::skipDnsName($packet, $len, $offset);
            if (null === $next) {
                return null;
            }
            $offset = $next + 4;
            if ($offset > $len) {
                return null;
            }
        }

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
            if (12 === $type && $rdlength > 0) {
                $target = self::readDnsName($packet, $len, $offset);
                if (null !== $target) {
                    $host = \rtrim($target[1], '.');

                    return '' !== $host ? $host : null;
                }
            }
            $offset += $rdlength;
        }

        return null;
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

        return ['hosts' => $hosts, 'weights' => $weights, 'records' => $records];
    }

    /** php-src DNS_* bitmask → wire qtype (ext/standard/dns.c php_dns_record_types). */
    private const DNS_TYPE_FLAGS = [
        StdlibConstants::DNS_A => ['name' => 'A', 'qtype' => 1],
        StdlibConstants::DNS_NS => ['name' => 'NS', 'qtype' => 2],
        StdlibConstants::DNS_CNAME => ['name' => 'CNAME', 'qtype' => 5],
        StdlibConstants::DNS_SOA => ['name' => 'SOA', 'qtype' => 6],
        StdlibConstants::DNS_PTR => ['name' => 'PTR', 'qtype' => 12],
        StdlibConstants::DNS_HINFO => ['name' => 'HINFO', 'qtype' => 13],
        StdlibConstants::DNS_MX => ['name' => 'MX', 'qtype' => 15],
        StdlibConstants::DNS_TXT => ['name' => 'TXT', 'qtype' => 16],
        StdlibConstants::DNS_AAAA => ['name' => 'AAAA', 'qtype' => 28],
        StdlibConstants::DNS_SRV => ['name' => 'SRV', 'qtype' => 33],
        StdlibConstants::DNS_NAPTR => ['name' => 'NAPTR', 'qtype' => 35],
        StdlibConstants::DNS_A6 => ['name' => 'A6', 'qtype' => 38],
        StdlibConstants::DNS_ANY => ['name' => 'ANY', 'qtype' => 255],
    ];

    private const DNS_VALID_TYPE_MASK = StdlibConstants::DNS_ALL | StdlibConstants::DNS_ANY;

    /**
     * dns_get_record() — DNS record list for hostname (#6392).
     *
     * php-src: ext/standard/dns.c — php_dns_get_record()
     * $raw — when true, prefer numeric type + binary `data` (php-src raw path; #23358).
     *
     * @return HashTable|false indexed list of associative record arrays
     */
    public static function dnsGetRecord(string $hostname, int $type = StdlibConstants::DNS_ANY, bool $raw = false)
    {
        self::validateDnsGetRecordType($type);

        $requested = self::expandDnsTypeBitmask($type);
        if ([] === $requested) {
            return false;
        }

        if ('' !== $hostname && !self::isValidDnsHostname($hostname)) {
            return false;
        }

        // php-src ext/standard/dns.c — empty hostname + DNS_ANY (default) → []; explicit DNS_ALL/NS/SOA still query root (#30322, re-#31935).
        if ('' === $hostname && StdlibConstants::DNS_ANY === $type) {
            return new HashTable();
        }

        $records = [];
        foreach ($requested as $flag => $meta) {
            $chunk = match ($flag) {
                StdlibConstants::DNS_A => '' === $hostname ? [] : self::collectARecords($hostname),
                StdlibConstants::DNS_NS => self::collectNsRecords($hostname),
                StdlibConstants::DNS_SOA => self::collectSoaRecords($hostname),
                StdlibConstants::DNS_MX => '' === $hostname ? [] : self::collectMxRecords($hostname),
                StdlibConstants::DNS_AAAA => '' === $hostname ? [] : self::collectAaaaRecords($hostname),
                default => [],
            };
            foreach ($chunk as $record) {
                $records[] = $raw ? self::dnsRecordToRaw($record, $meta['qtype']) : $record;
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
     * php-src dns.c raw mode — type as int + binary rdata in `data` (#23358).
     *
     * @param HashTable $record parsed associative record
     */
    private static function dnsRecordToRaw(HashTable $record, int $qtype): HashTable
    {
        $out = new HashTable();
        $host = $record->find('host');
        if (null !== $host && Variable::TYPE_STRING === $host->type) {
            self::addDnsStringField($out, 'host', $host->toString());
        }
        $class = $record->find('class');
        if (null !== $class && Variable::TYPE_STRING === $class->type) {
            self::addDnsStringField($out, 'class', $class->toString());
        } else {
            self::addDnsStringField($out, 'class', 'IN');
        }
        $ttl = $record->find('ttl');
        if (null !== $ttl && Variable::TYPE_INTEGER === $ttl->type) {
            self::addDnsIntField($out, 'ttl', $ttl->toInt());
        }
        self::addDnsIntField($out, 'type', $qtype);

        $data = '';
        if (1 === $qtype) {
            $ip = $record->find('ip');
            if (null !== $ip && Variable::TYPE_STRING === $ip->type) {
                $packed = @\inet_pton($ip->toString());
                $data = false === $packed ? '' : $packed;
            }
        } elseif (28 === $qtype) {
            $ipv6 = $record->find('ipv6');
            if (null !== $ipv6 && Variable::TYPE_STRING === $ipv6->type) {
                $packed = @\inet_pton($ipv6->toString());
                $data = false === $packed ? '' : $packed;
            }
        }
        self::addDnsStringField($out, 'data', $data);

        return $out;
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
            throw new \ValueError('dns_get_record(): Argument #2 ($type) must be a DNS_* constant');
        }
    }

    /**
     * @return array<int, array{name: string, qtype: int}>
     */
    private static function expandDnsTypeBitmask(int $type): array
    {
        if (StdlibConstants::DNS_ALL === $type || 0 !== ($type & StdlibConstants::DNS_ANY)) {
            return self::DNS_TYPE_FLAGS;
        }

        $requested = [];
        foreach (self::DNS_TYPE_FLAGS as $flag => $meta) {
            if (StdlibConstants::DNS_ANY === $flag) {
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

        $records = [];
        $seen = [];
        $packet = self::queryViaUdp($hostname, self::DNS_RECORD_TYPES['A']);
        if (null !== $packet) {
            foreach (self::parseDnsIpv4RecordsWithTtl($packet) as $entry) {
                $ip = $entry['ip'];
                if (isset($seen[$ip])) {
                    continue;
                }
                $seen[$ip] = true;
                $records[] = self::makeDnsRecord($hostname, 'A', ['ttl' => $entry['ttl'], 'ip' => $ip]);
            }
        }

        if ([] !== $records) {
            return $records;
        }

        $hostsIps = self::resolveViaEtcHosts($hostname);
        if (null === $hostsIps || [] === $hostsIps) {
            return [];
        }

        $hostsIps = self::finalizeIpv4ResolverList($hostname, $hostsIps);
        foreach ($hostsIps as $ip) {
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
    private static function collectNsRecords(string $hostname): array
    {
        $packet = self::queryViaUdp($hostname, self::DNS_RECORD_TYPES['NS']);
        if (null === $packet) {
            return [];
        }

        $records = [];
        foreach (self::parseDnsNsRecords($packet) as $entry) {
            $records[] = self::makeDnsRecord($hostname, 'NS', [
                'ttl' => $entry['ttl'],
                'target' => $entry['target'],
            ]);
        }

        return $records;
    }

    /** @return list<HashTable> */
    private static function collectSoaRecords(string $hostname): array
    {
        $packet = self::queryViaUdp($hostname, self::DNS_RECORD_TYPES['SOA']);
        if (null === $packet) {
            return [];
        }

        $records = [];
        foreach (self::parseDnsSoaRecords($packet) as $entry) {
            $records[] = self::makeDnsRecord($hostname, 'SOA', [
                'ttl' => $entry['ttl'],
                'mname' => $entry['mname'],
                'rname' => $entry['rname'],
                'serial' => $entry['serial'],
                'refresh' => $entry['refresh'],
                'retry' => $entry['retry'],
                'expire' => $entry['expire'],
                'minimum-ttl' => $entry['minimum'],
            ]);
        }

        return $records;
    }

    /** @return list<HashTable> */
    private static function collectMxRecords(string $hostname): array
    {
        $mx = self::dnsGetMx($hostname);
        if (false === $mx) {
            return [];
        }

        $records = [];
        foreach ($mx['records'] as $entry) {
            $records[] = self::makeDnsRecord($hostname, 'MX', [
                'ttl' => $entry['ttl'],
                'pri' => $entry['weight'],
                'target' => $entry['host'],
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
        if (isset($fields['ttl']) && \is_int($fields['ttl'])) {
            self::addDnsIntField($ht, 'ttl', $fields['ttl']);
        }
        self::addDnsStringField($ht, 'type', $typeName);
        foreach ($fields as $key => $value) {
            if ('ttl' === $key) {
                continue;
            }
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

        $entries = self::parseDnsIpv4RecordsWithTtl($packet);
        if ([] === $entries) {
            return null;
        }

        return \array_values(\array_map(static fn (array $entry): string => $entry['ip'], $entries));
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
     * @return list<array{ip: string, ttl: int}>
     */
    public static function parseDnsIpv4RecordsWithTtl(string $packet): array
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

        $entries = [];
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
            $ttl = self::readUint32($packet, $offset + 4);
            $offset += 8;
            $rdlength = self::readUint16($packet, $offset);
            $offset += 2;
            if ($offset + $rdlength > $len) {
                break;
            }
            if (1 === $type && 4 === $rdlength) {
                $ip = \inet_ntop(\substr($packet, $offset, 4)) ?: '';
                if ('' !== $ip) {
                    $entries[] = ['ip' => $ip, 'ttl' => $ttl];
                }
            }
            $offset += $rdlength;
        }

        return $entries;
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
        if ('' === $hostname) {
            return "\0";
        }
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
     * @return list<array{target: string, ttl: int}>
     */
    public static function parseDnsNsRecords(string $packet): array
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

        $ns = [];
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
            $ttl = self::readUint32($packet, $offset + 4);
            $offset += 8;
            $rdlength = self::readUint16($packet, $offset);
            $offset += 2;
            if ($offset + $rdlength > $len) {
                break;
            }
            if (2 === $type) {
                $target = self::readDnsName($packet, $len, $offset);
                if (null !== $target) {
                    $ns[] = ['target' => $target[1], 'ttl' => $ttl];
                }
            }
            $offset += $rdlength;
        }

        return $ns;
    }

    /**
     * @return list<array{mname: string, rname: string, serial: int, refresh: int, retry: int, expire: int, minimum: int, ttl: int}>
     */
    public static function parseDnsSoaRecords(string $packet): array
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

        $soa = [];
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
            $ttl = self::readUint32($packet, $offset + 4);
            $offset += 8;
            $rdlength = self::readUint16($packet, $offset);
            $offset += 2;
            if ($offset + $rdlength > $len) {
                break;
            }
            if (6 === $type && $rdlength >= 20) {
                $rdataOffset = $offset;
                $mname = self::readDnsName($packet, $len, $rdataOffset);
                if (null === $mname) {
                    $offset += $rdlength;
                    continue;
                }
                $rdataOffset = $mname[0];
                $rname = self::readDnsName($packet, $len, $rdataOffset);
                if (null === $rname) {
                    $offset += $rdlength;
                    continue;
                }
                $rdataOffset = $rname[0];
                if ($rdataOffset + 20 > $len) {
                    break;
                }
                $soa[] = [
                    'mname' => $mname[1],
                    'rname' => $rname[1],
                    'serial' => self::readUint32($packet, $rdataOffset),
                    'refresh' => self::readUint32($packet, $rdataOffset + 4),
                    'retry' => self::readUint32($packet, $rdataOffset + 8),
                    'expire' => self::readUint32($packet, $rdataOffset + 12),
                    'minimum' => self::readUint32($packet, $rdataOffset + 16),
                    'ttl' => $ttl,
                ];
            }
            $offset += $rdlength;
        }

        return $soa;
    }

    /**
     * @return list<array{host: string, weight: int, ttl: int}>
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
            $ttl = self::readUint32($packet, $offset + 4);
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
                    $mx[] = ['host' => $exchange[1], 'weight' => $weight, 'ttl' => $ttl];
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

    private static function readUint32(string $packet, int $offset): int
    {
        return (self::readUint16($packet, $offset) << 16) | self::readUint16($packet, $offset + 2);
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
