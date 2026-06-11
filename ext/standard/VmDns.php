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
 * PHP_FUNCTION(gethostbyname), PHP_FUNCTION(checkdnsrr), PHP_FUNCTION(dns_check_record)
 */
final class VmDns
{
    public const ERR_NONE = 0;

    public const ERR_INVALID_ADDRESS = 1;

    public const ERR_NOT_FOUND = 2;

    private const MAX_ADDRS = 64;

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
