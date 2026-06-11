<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * DNS helpers for stdlib builtins (issue #3707, #5854, #7315).
 *
 * VM resolves via /etc/hosts without host Zend DNS builtins; optional libc getaddrinfo/res_query when FFI is loaded.
 * JIT/AOT: lib/JIT/Builtin/GethostbynamelRuntime.php (__compiler_gethostbynamel),
 * CheckdnsrrRuntime.php (__compiler_checkdnsrr).
 *
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel), PHP_FUNCTION(gethostbyaddr),
 * PHP_FUNCTION(gethostbyname), PHP_FUNCTION(checkdnsrr), PHP_FUNCTION(dns_check_record),
 * PHP_FUNCTION(dns_get_mx)
 */
final class VmDns
{
    public const ERR_NONE = 0;

    public const ERR_INVALID_ADDRESS = 1;

    public const ERR_NOT_FOUND = 2;

    private const MAX_ADDRS = 64;

    private const MAX_MX = 64;

    private const HFIXEDSZ = 12;

    private const QFIXEDSZ = 4;

    private const INT16SZ = 2;

    private const INT32SZ = 4;

    private const DNS_UDP_TIMEOUT_SEC = 2;

    private const AF_INET = 2;

    private const SOCK_STREAM = 1;

    private const NI_MAXHOST = 1025;

    private static ?\FFI $ffi = null;

    private static ?\FFI $dnsFfi = null;

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

        if (self::ffiEnabled()) {
            $ffiResult = self::checkdnsrrViaResQuery($hostname, $qtype);
            if (null !== $ffiResult) {
                return $ffiResult;
            }
        }

        return self::checkdnsrrPurePhp($hostname, $qtype);
    }

    /**
     * @return HashTable|false
     */
    public static function gethostbynamel(string $hostname)
    {
        if ('' === $hostname || \strlen($hostname) > 255) {
            return false;
        }

        $ips = self::resolveViaEtcHosts($hostname);
        if (null === $ips || [] === $ips) {
            $ips = self::resolveViaGetaddrinfo($hostname);
        }
        if (null === $ips || [] === $ips) {
            return false;
        }

        $ht = new HashTable();
        foreach ($ips as $index => $ip) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($ip);
            $ht->add((string) $index, $var);
        }
        if (0 === $ht->getNumElements()) {
            return false;
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
    /**
     * dns_get_mx() — MX hostnames and preference weights (#4125).
     *
     * php-src: ext/standard/dns.c — PHP_FUNCTION(dns_get_mx), php_dns_mx()
     *
     * @return array{ok: bool, hosts: HashTable, weights: HashTable}
     */
    public static function dnsGetMx(string $hostname): array
    {
        $hosts = new HashTable();
        $weights = new HashTable();
        if ('' === $hostname || \strlen($hostname) > 255) {
            return ['ok' => false, 'hosts' => $hosts, 'weights' => $weights];
        }

        $records = self::dnsGetMxViaResQuery($hostname);
        if (null === $records) {
            $records = self::dnsGetMxViaUdp($hostname);
        }
        if (null === $records) {
            return ['ok' => false, 'hosts' => $hosts, 'weights' => $weights];
        }

        foreach ($records as $index => [$mxHost, $weight]) {
            $hostVar = new Variable(Variable::TYPE_STRING);
            $hostVar->string($mxHost);
            $hosts->add((string) $index, $hostVar);

            $weightVar = new Variable(Variable::TYPE_INTEGER);
            $weightVar->int($weight);
            $weights->add((string) $index, $weightVar);
        }

        return [
            'ok' => $hosts->getNumElements() > 0,
            'hosts' => $hosts,
            'weights' => $weights,
        ];
    }

    public static function gethostbyaddr(string $ipAddress, ?int &$error = null)
    {
        $error = self::ERR_NONE;
        if ('' === $ipAddress || \strlen($ipAddress) > 255) {
            $error = self::ERR_INVALID_ADDRESS;

            return false;
        }

        $name = self::resolveHostnameViaEtcHosts($ipAddress);
        if (null === $name || '' === $name) {
            $name = self::resolveHostnameViaGetnameinfo($ipAddress);
        }
        if (null === $name || '' === $name) {
            $error = self::isValidIpv4Address($ipAddress)
                ? self::ERR_NOT_FOUND
                : self::ERR_INVALID_ADDRESS;

            return false;
        }

        return $name;
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

    /**
     * @return string|null null when libc FFI path unavailable or lookup failed
     */
    private static function resolveHostnameViaGetnameinfo(string $ip): ?string
    {
        if (!self::inetPtonIpv4($ip)) {
            return null;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::ffi();
        } catch (\Throwable) {
            return null;
        }

        $sin = $ffi->new('struct sockaddr_in');
        $sin->sin_family = self::AF_INET;
        $rc = (int) $ffi->inet_pton(self::AF_INET, $ip, \FFI::addr($sin->sin_addr));
        if (1 !== $rc) {
            return null;
        }

        $hostbuf = $ffi->new('char['.self::NI_MAXHOST.']');
        $sa = $ffi->cast('struct sockaddr *', \FFI::addr($sin));
        $gnRc = (int) $ffi->getnameinfo(
            $sa,
            \FFI::sizeof($sin),
            $hostbuf,
            self::NI_MAXHOST,
            null,
            0,
            0
        );
        if (0 !== $gnRc) {
            return null;
        }

        $name = \FFI::string($hostbuf);

        return '' !== $name ? $name : null;
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
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
     * @return list<string>|null null when libc FFI path unavailable
     */
    private static function resolveViaGetaddrinfo(string $hostname): ?array
    {
        if (!\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::ffi();
        } catch (\Throwable) {
            return null;
        }

        $hints = $ffi->new('struct addrinfo');
        $hints->ai_family = self::AF_INET;
        $hints->ai_socktype = self::SOCK_STREAM;
        $hints->ai_flags = 0;
        $hints->ai_protocol = 0;

        $resHead = $ffi->new('struct addrinfo *');
        $rc = (int) $ffi->getaddrinfo($hostname, null, \FFI::addr($hints), \FFI::addr($resHead));
        if (0 !== $rc) {
            return null;
        }

        $stored = [];
        $rp = $resHead[0];
        while (null !== $rp) {
            if (self::AF_INET === (int) $rp->ai_family && null !== $rp->ai_addr) {
                $sin = $ffi->cast('struct sockaddr_in *', $rp->ai_addr);
                $buf = $ffi->new('char[16]');
                $ntop = $ffi->inet_ntop(
                    self::AF_INET,
                    \FFI::addr($sin->sin_addr),
                    $buf,
                    16
                );
                if (null !== $ntop) {
                    $ip = \FFI::string($buf);
                    if ('' !== $ip && !\in_array($ip, $stored, true) && \count($stored) < self::MAX_ADDRS) {
                        $stored[] = $ip;
                    }
                }
            }
            $rp = $rp->ai_next;
        }
        $ffi->freeaddrinfo($resHead);

        return $stored;
    }

    /**
     * @return bool|null null when FFI path unavailable
     */
    /**
     * @return list<array{0: string, 1: int}>|null null when libc res_query unavailable
     */
    private static function dnsGetMxViaResQuery(string $hostname): ?array
    {
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::dnsFfi();
        } catch (\Throwable) {
            return null;
        }

        $ffi->res_init();
        $buf = $ffi->new('unsigned char[1024]');
        $answerLen = (int) $ffi->res_query(
            $hostname,
            self::DNS_CLASS_IN,
            self::DNS_RECORD_TYPES['MX'],
            $buf,
            1024
        );
        if ($answerLen <= 0) {
            return [];
        }

        $packet = \FFI::string($buf, $answerLen);

        return self::parseMxRecordsFromPacket($packet, $answerLen);
    }

    /**
     * Pure-PHP UDP MX lookup when libc res_query FFI is unavailable (#4125, #7934).
     *
     * @return list<array{0: string, 1: int}>|null null when no nameserver
     */
    private static function dnsGetMxViaUdp(string $hostname): ?array
    {
        $nameservers = self::readResolvConfNameservers();
        if ([] === $nameservers) {
            return null;
        }

        $query = self::buildDnsQueryPacket($hostname, self::DNS_RECORD_TYPES['MX']);
        foreach ($nameservers as $nameserver) {
            $response = self::udpDnsExchange($nameserver, $query);
            if (null === $response || '' === $response) {
                continue;
            }
            $records = self::parseMxRecordsFromPacket($response, \strlen($response));
            if ([] !== $records) {
                return $records;
            }
        }

        return [];
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function parseMxRecordsFromPacket(string $packet, int $len): array
    {
        if ($len < self::HFIXEDSZ) {
            return [];
        }

        $qdcount = self::readUint16Be($packet, 4);
        $ancount = self::readUint16Be($packet, 6);
        $cp = self::HFIXEDSZ;
        $end = $len;

        for ($q = 0; $q < $qdcount; ++$q) {
            $skip = self::dnSkipname($packet, $cp, $end);
            if (false === $skip || $skip + self::QFIXEDSZ > $end) {
                return [];
            }
            $cp = $skip + self::QFIXEDSZ;
        }

        $records = [];
        for ($a = 0; $a < $ancount && $cp < $end; ++$a) {
            $skip = self::dnSkipname($packet, $cp, $end);
            if (false === $skip) {
                break;
            }
            $cp = $skip;
            if ($cp + (self::INT16SZ * 3) + self::INT32SZ > $end) {
                break;
            }

            $type = self::readUint16Be($packet, $cp);
            $cp += self::INT16SZ + self::INT16SZ + self::INT32SZ;
            if ($cp + self::INT16SZ > $end) {
                break;
            }
            $rdlength = self::readUint16Be($packet, $cp);
            $cp += self::INT16SZ;
            if ($cp + $rdlength > $end) {
                break;
            }

            $rdataStart = $cp;
            if (self::DNS_RECORD_TYPES['MX'] !== $type) {
                $cp = $rdataStart + $rdlength;
                continue;
            }

            if ($rdlength < self::INT16SZ) {
                $cp = $rdataStart + $rdlength;
                continue;
            }

            $weight = self::readUint16Be($packet, $rdataStart);
            $exchange = self::dnExpand($packet, $rdataStart + self::INT16SZ, $end);
            if (false === $exchange) {
                $cp = $rdataStart + $rdlength;
                continue;
            }
            if ('.' === $exchange) {
                $exchange = '';
            }

            $records[] = [$exchange, $weight];
            $cp = $rdataStart + $rdlength;
            if (\count($records) >= self::MAX_MX) {
                break;
            }
        }

        return $records;
    }

    private static function readUint16Be(string $packet, int $offset): int
    {
        return (ord($packet[$offset]) << 8) | ord($packet[$offset + 1]);
    }

    /**
     * @return int|false
     */
    private static function dnSkipname(string $packet, int $offset, int $end)
    {
        while ($offset < $end) {
            $len = ord($packet[$offset]);
            if (0 === $len) {
                return $offset + 1;
            }
            if (($len & 0xC0) === 0xC0) {
                return $offset + 2;
            }
            if ($len > 63) {
                return false;
            }
            $offset += 1 + $len;
        }

        return false;
    }

    /**
     * @return string|false
     */
    private static function dnExpand(string $packet, int $offset, int $end)
    {
        $labels = [];
        $jumps = 0;

        while ($offset < $end) {
            $len = ord($packet[$offset]);
            if (0 === $len) {
                break;
            }
            if (($len & 0xC0) === 0xC0) {
                if ($offset + 1 >= $end) {
                    return false;
                }
                $offset = (($len & 0x3F) << 8) | ord($packet[$offset + 1]);
                if (++$jumps > 256) {
                    return false;
                }
                continue;
            }
            if ($len > 63) {
                return false;
            }
            ++$offset;
            if ($offset + $len > $end) {
                return false;
            }
            $labels[] = \substr($packet, $offset, $len);
            $offset += $len;
        }

        if ([] === $labels) {
            return '.';
        }

        return \implode('.', $labels);
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
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_client(
            'udp://'.$nameserver.':53',
            $errno,
            $errstr,
            self::DNS_UDP_TIMEOUT_SEC,
            STREAM_CLIENT_CONNECT
        );
        if (false === $socket) {
            return null;
        }

        \stream_set_timeout($socket, self::DNS_UDP_TIMEOUT_SEC);
        $written = @\fwrite($socket, $query);
        if (false === $written || $written !== \strlen($query)) {
            \fclose($socket);

            return null;
        }

        $response = @\stream_get_contents($socket);
        \fclose($socket);

        return false === $response ? null : $response;
    }

    private static function checkdnsrrViaResQuery(string $hostname, int $qtype): ?bool
    {
        if (!\extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = self::dnsFfi();
        } catch (\Throwable) {
            return null;
        }
        $ffi->res_init();
        $buf = $ffi->new('unsigned char[1024]');
        $rc = (int) $ffi->res_query($hostname, self::DNS_CLASS_IN, $qtype, $buf, 1024);

        return $rc > 0;
    }

    /**
     * Pure-PHP fallback when libc res_query is unavailable (#7934, #7315 phase 2).
     *
     * A records: probe /etc/hosts then optional getaddrinfo FFI. Other qtypes need res_query.
     */
    private static function checkdnsrrPurePhp(string $hostname, int $qtype): bool
    {
        if (1 === $qtype) {
            $ips = self::resolveViaEtcHosts($hostname);
            if (null !== $ips && [] !== $ips) {
                return true;
            }
            if (self::ffiEnabled()) {
                $ips = self::resolveViaGetaddrinfo($hostname);

                return null !== $ips && [] !== $ips;
            }

            return false;
        }

        return false;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function dnsFfi(): \FFI
    {
        if (null !== self::$dnsFfi) {
            return self::$dnsFfi;
        }

        $cdef = <<<'CDEF'
int res_init(void);
int res_query(const char *dname, int class, int type, unsigned char *answer, int anslen);
CDEF;

        foreach (['libresolv.so', 'libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$dnsFfi = \FFI::cdef($cdef, $lib);

                return self::$dnsFfi;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('libc res_query FFI unavailable');
    }

    private static function ffi(): \FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }

        $cdef = <<<'CDEF'
typedef unsigned int socklen_t;
typedef unsigned short int sa_family_t;
typedef unsigned short int in_port_t;

struct in_addr {
    unsigned int s_addr;
};

struct sockaddr_in {
    sa_family_t sin_family;
    in_port_t sin_port;
    struct in_addr sin_addr;
    unsigned char sin_zero[8];
};

struct sockaddr {
    sa_family_t sa_family;
    char sa_data[14];
};

struct addrinfo {
    int ai_flags;
    int ai_family;
    int ai_socktype;
    int ai_protocol;
    socklen_t ai_addrlen;
    struct sockaddr *ai_addr;
    char *ai_canonname;
    struct addrinfo *ai_next;
};

int getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, struct addrinfo **res);
void freeaddrinfo(struct addrinfo *res);
const char *inet_ntop(int af, const void *src, char *dst, socklen_t size);
int inet_pton(int af, const char *src, void *dst);
int getnameinfo(const struct sockaddr *sa, socklen_t salen, char *host, socklen_t hostlen, char *serv, socklen_t servlen, int flags);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('libc getaddrinfo FFI unavailable');
    }

    /**
     * Pure-PHP fallback when FFI is absent (reads /etc/hosts only).
     *
     * @return list<string>|null
     */
    private static function resolveViaEtcHosts(string $hostname): ?array
    {
        $path = '/etc/hosts';
        if (!\is_readable($path)) {
            return null;
        }
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
                    if (!\in_array($ip, $stored, true) && \count($stored) < self::MAX_ADDRS) {
                        $stored[] = $ip;
                    }
                    break;
                }
            }
        }

        return $stored;
    }
}
