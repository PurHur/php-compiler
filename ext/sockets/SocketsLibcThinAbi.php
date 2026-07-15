<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * Thin libc ABI for ext/sockets BSD socket syscalls (#19286, #3399).
 *
 * Quarantines socket(2)/connect(2)/send(2)/recv(2)/close(2) FFI — no permanent
 * runtime/*.c socket table. php-src: ext/sockets/sockets.c.
 */
final class SocketsLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function readErrno(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    public static function strerror(int $errno): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 'Unknown error '.$errno;
        }
        $ptr = $ffi->strerror($errno);
        if (null === $ptr) {
            return 'Unknown error '.$errno;
        }

        return \FFI::string($ptr);
    }

    public static function socket(int $domain, int $type, int $protocol): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->socket($domain, $type, $protocol);
    }

    public static function connectInet(int $fd, string $addr, int $port): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $sa = self::makeSockaddrIn($ffi, $addr, $port);
        if (null === $sa) {
            return -1;
        }

        return (int) $ffi->connect($fd, \FFI::addr($sa), 16);
    }

    public static function bindInet(int $fd, string $addr, int $port): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $sa = self::makeSockaddrIn($ffi, $addr, $port);
        if (null === $sa) {
            return -1;
        }

        return (int) $ffi->bind($fd, \FFI::addr($sa), 16);
    }

    public static function listen(int $fd, int $backlog): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->listen($fd, $backlog);
    }

    public static function accept(int $fd): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->accept($fd, null, null);
    }

    /**
     * @return array{0: string, 1: int}|false
     */
    public static function getsocknameInet(int $fd): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $sa = $ffi->new('struct sockaddr_in');
        $len = $ffi->new('unsigned int');
        $len->cdata = 16;
        if (0 !== (int) $ffi->getsockname($fd, \FFI::addr($sa), \FFI::addr($len))) {
            return false;
        }
        $buf = $ffi->new('char[64]');
        if (null === $ffi->inet_ntop(2, $ffi->cast('void*', \FFI::addr($sa->sin_addr)), $buf, 64)) {
            return false;
        }

        return [\FFI::string($buf), (int) $ffi->ntohs($sa->sin_port)];
    }

    public static function send(int $fd, string $buf, int $length, int $flags = 0): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        if ($length <= 0) {
            return 0;
        }
        $payload = \substr($buf, 0, $length);
        $c = $ffi->new('char['.\strlen($payload).']');
        \FFI::memcpy($c, $payload, \strlen($payload));

        return (int) $ffi->send($fd, $c, \strlen($payload), $flags);
    }

    /**
     * @return string|false
     */
    public static function recv(int $fd, int $length, int $flags = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if ($length <= 0) {
            return '';
        }
        $c = $ffi->new('char['.$length.']');
        $n = (int) $ffi->recv($fd, $c, $length, $flags);
        if ($n < 0) {
            return false;
        }
        if (0 === $n) {
            return '';
        }

        return \FFI::string($c, $n);
    }

    public static function close(int $fd): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->close($fd);
    }

    public static function fcntlGetFl(int $fd): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fcntl($fd, 3, 0);
    }

    public static function fcntlSetFl(int $fd, int $flags): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fcntl($fd, 4, $flags);
    }

    public static function sockatmark(int $fd): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->sockatmark($fd);
    }

    public static function shutdown(int $fd, int $how): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->shutdown($fd, $how);
    }

    /**
     * @return \FFI\CData|null struct sockaddr_in
     */
    private static function makeSockaddrIn(\FFI $ffi, string $addr, int $port): mixed
    {
        $sa = $ffi->new('struct sockaddr_in');
        $ffi->memset(\FFI::addr($sa), 0, 16);
        $sa->sin_family = 2; // AF_INET
        $sa->sin_port = $ffi->htons($port & 0xffff);
        $dst = \FFI::addr($sa->sin_addr);
        if (1 !== (int) $ffi->inet_pton(2, $addr, $ffi->cast('void*', $dst))) {
            return null;
        }

        return $sa;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower((string) $v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi') && !\class_exists(\FFI::class, false)) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
struct in_addr { unsigned int s_addr; };
struct sockaddr_in {
    unsigned short sin_family;
    unsigned short sin_port;
    struct in_addr sin_addr;
    char sin_zero[8];
};
int socket(int domain, int type, int protocol);
int connect(int sockfd, const void *addr, unsigned int addrlen);
int bind(int sockfd, const void *addr, unsigned int addrlen);
int listen(int sockfd, int backlog);
int accept(int sockfd, void *addr, unsigned int *addrlen);
int getsockname(int sockfd, void *addr, unsigned int *addrlen);
long send(int sockfd, const void *buf, unsigned long len, int flags);
long recv(int sockfd, void *buf, unsigned long len, int flags);
int close(int fd);
int fcntl(int fd, int cmd, ...);
int sockatmark(int sockfd);
int shutdown(int sockfd, int how);
int inet_pton(int af, const char *src, void *dst);
const char *inet_ntop(int af, const void *src, char *dst, unsigned int size);
unsigned short htons(unsigned short hostshort);
unsigned short ntohs(unsigned short netshort);
int *__errno_location(void);
char *strerror(int errnum);
void *memset(void *s, int c, unsigned long n);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$unavailable = true;

        return null;
    }
}
