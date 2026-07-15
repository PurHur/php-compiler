<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\VmFsPathNative;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;

/**
 * VM helpers for ext/sockets builtins (php-src ext/sockets/sockets.c; #6544, #6203, #19286).
 *
 * Uses {@see SocketsLibcThinAbi} — no host Zend socket_*() delegation (#8176).
 */
final class VmSockets
{
    public const AF_UNIX = 1;
    public const AF_INET = 2;
    public const AF_INET6 = 10;

    public const PHP_NORMAL_READ = 1;
    public const PHP_BINARY_READ = 2;

    private const O_NONBLOCK = 2048;

    /** @var array<int, int> Socket object id => last errno */
    private static array $socketErrors = [];

    private static int $lastError = 0;

    public static function isAtmarkSupported(): bool
    {
        return SocketsLibcThinAbi::available();
    }

    public static function isSocketApiSupported(): bool
    {
        return SocketsLibcThinAbi::available();
    }

    public static function atmarkForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::atmarkForFd($fd);
    }

    public static function atmarkForFd(int $fd): bool
    {
        $r = SocketsLibcThinAbi::sockatmark($fd);

        return $r >= 0 && 0 !== $r;
    }

    /** php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_nonblock) via fcntl(F_SETFL). */
    public static function setNonblockForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::setNonblockForFd($fd);
    }

    /** php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_block) via fcntl(F_SETFL). */
    public static function setBlockForObject(ObjectEntry $object): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        return self::setBlockForFd($fd);
    }

    public static function setNonblockForFd(int $fd): bool
    {
        $flags = SocketsLibcThinAbi::fcntlGetFl($fd);
        if (-1 === $flags) {
            return false;
        }

        return -1 !== SocketsLibcThinAbi::fcntlSetFl($fd, $flags | self::O_NONBLOCK);
    }

    public static function setBlockForFd(int $fd): bool
    {
        $flags = SocketsLibcThinAbi::fcntlGetFl($fd);
        if (-1 === $flags) {
            return false;
        }

        return -1 !== SocketsLibcThinAbi::fcntlSetFl($fd, $flags & ~self::O_NONBLOCK);
    }

    /** php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_shutdown) via shutdown(2). */
    public static function isShutdownSupported(): bool
    {
        return SocketsLibcThinAbi::available();
    }

    public static function shutdownForFd(int $fd, int $how): bool
    {
        return 0 === SocketsLibcThinAbi::shutdown($fd, $how);
    }

    /**
     * php-src: PHP_FUNCTION(socket_create) — domain must be AF_UNIX|AF_INET|AF_INET6.
     *
     * @return ObjectEntry|false
     */
    public static function create(int $domain, int $type, int $protocol, \PHPCompiler\VM\Context $ctx): ObjectEntry|false
    {
        if (!\in_array($domain, [self::AF_UNIX, self::AF_INET, self::AF_INET6], true)) {
            throw new \ValueError(
                'socket_create(): Argument #1 ($domain) must be one of AF_UNIX, AF_INET6, or AF_INET'
            );
        }
        if (!SocketsLibcThinAbi::available()) {
            return false;
        }
        $fd = SocketsLibcThinAbi::socket($domain, $type, $protocol);
        if ($fd < 0) {
            self::recordError(null, SocketsLibcThinAbi::readErrno());

            return false;
        }

        $object = VmSocket::wrapOwnedFd($fd, $ctx);
        self::$socketErrors[$object->id] = 0;

        return $object;
    }

    public static function connect(ObjectEntry $object, string $addr, int $port, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $rc = SocketsLibcThinAbi::connectInet($fd, $addr, $port);
        if (0 === $rc) {
            self::$socketErrors[$object->id] = 0;

            return true;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        self::recordError($object, $errno);
        VmSockets::triggerWarning(
            $frame,
            \sprintf(
                'socket_connect(): unable to connect [%d]: %s',
                $errno,
                SocketsLibcThinAbi::strerror($errno)
            )
        );

        return false;
    }

    /**
     * @return int|false bytes written
     */
    public static function write(ObjectEntry $object, string $buf, ?int $length): int|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $len = null === $length ? \strlen($buf) : $length;
        if ($len < 0) {
            throw new \ValueError('socket_write(): Argument #3 ($length) must be greater than or equal to 0');
        }
        if ($len > \strlen($buf)) {
            $len = \strlen($buf);
        }
        $n = SocketsLibcThinAbi::send($fd, $buf, $len, 0);
        if ($n < 0) {
            self::recordError($object, SocketsLibcThinAbi::readErrno());

            return false;
        }
        self::$socketErrors[$object->id] = 0;

        return $n;
    }

    /**
     * @return string|false
     */
    public static function read(ObjectEntry $object, int $length, int $type = self::PHP_BINARY_READ): string|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if ($length < 1) {
            throw new \ValueError(
                'socket_read(): Argument #2 ($length) must be greater than 0'
            );
        }
        if (self::PHP_NORMAL_READ === $type) {
            return self::readNormal($object, $fd, $length);
        }
        $data = SocketsLibcThinAbi::recv($fd, $length, 0);
        if (false === $data) {
            self::recordError($object, SocketsLibcThinAbi::readErrno());

            return false;
        }
        self::$socketErrors[$object->id] = 0;

        return $data;
    }

    public static function close(ObjectEntry $object): void
    {
        $fd = VmSocket::ownedFdForObject($object);
        if (null !== $fd) {
            SocketsLibcThinAbi::close($fd);
        }
        VmSocket::release($object);
        unset(self::$socketErrors[$object->id]);
    }

    public static function lastError(?ObjectEntry $object = null): int
    {
        if (null !== $object) {
            return self::$socketErrors[$object->id] ?? 0;
        }

        return self::$lastError;
    }

    public static function clearError(?ObjectEntry $object = null): void
    {
        if (null !== $object) {
            self::$socketErrors[$object->id] = 0;

            return;
        }
        self::$lastError = 0;
    }

    public static function strerror(int $errno): string
    {
        return SocketsLibcThinAbi::strerror($errno);
    }

    /** @return list<int> */
    public static function discoverNewSocketFds(array $beforeSockets): array
    {
        $new = [];
        foreach (self::enumerateSocketFds() as $fd => $_target) {
            if (!isset($beforeSockets[$fd])) {
                $new[] = $fd;
            }
        }
        sort($new);

        return $new;
    }

    public static function discoverNewSocketFd(array $beforeSockets): ?int
    {
        $new = self::discoverNewSocketFds($beforeSockets);

        return $new[0] ?? null;
    }

    public static function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /** Exposed for {@see VmSocket::fdForObject()}. */
    public static function getsocknameFd(int $fd, string &$addr): bool
    {
        $name = SocketsLibcThinAbi::getsocknameInet($fd);
        if (false === $name) {
            return false;
        }
        $addr = $name[0];

        return true;
    }

    /**
     * @return array<int, string> fd => socket:[inode]
     */
    public static function enumerateSocketFds(): array
    {
        $out = [];
        foreach (glob('/proc/self/fd/*') ?: [] as $path) {
            $target = VmFsPathNative::readlink($path);
            if (false === $target || !str_starts_with($target, 'socket:')) {
                continue;
            }
            $out[(int) basename($path)] = $target;
        }

        return $out;
    }

    private static function recordError(?ObjectEntry $object, int $errno): void
    {
        self::$lastError = $errno;
        if (null !== $object) {
            self::$socketErrors[$object->id] = $errno;
        }
    }

    /**
     * php-src PHP_NORMAL_READ — stop after first newline (included).
     *
     * @return string|false
     */
    private static function readNormal(ObjectEntry $object, int $fd, int $length): string|false
    {
        $out = '';
        while (\strlen($out) < $length) {
            $chunk = SocketsLibcThinAbi::recv($fd, 1, 0);
            if (false === $chunk) {
                self::recordError($object, SocketsLibcThinAbi::readErrno());

                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $out .= $chunk;
            if ("\n" === $chunk) {
                break;
            }
        }
        self::$socketErrors[$object->id] = 0;

        return $out;
    }
}
