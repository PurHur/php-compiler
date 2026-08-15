<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\VmFs;
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

    /**
     * socket_select() via poll(2) (php-src ext/sockets/sockets.c; #6395).
     *
     * @param list<array{key: int|string, object: ObjectEntry, fd: int}>|null $read
     * @param list<array{key: int|string, object: ObjectEntry, fd: int}>|null $write
     * @param list<array{key: int|string, object: ObjectEntry, fd: int}>|null $except
     *
     * @return array{read: list<array{key: int|string, object: ObjectEntry, fd: int}>, write: list<array{key: int|string, object: ObjectEntry, fd: int}>, except: list<array{key: int|string, object: ObjectEntry, fd: int}>, count: int}|false
     */
    public static function select(
        ?array $read,
        ?array $write,
        ?array $except,
        int $seconds,
        int $microseconds,
    ): array|false {
        $polLin = 0x001;
        $polLout = 0x004;
        $polLerr = 0x008;
        $polLhup = 0x010;
        $polLpri = 0x002;

        /** @var list<array{slot: array{key: int|string, object: ObjectEntry, fd: int}, events: int, kind: int}> $entries */
        $entries = [];
        if (null !== $read) {
            foreach ($read as $slot) {
                $entries[] = ['slot' => $slot, 'events' => $polLin | $polLhup, 'kind' => 1];
            }
        }
        if (null !== $write) {
            foreach ($write as $slot) {
                $entries[] = ['slot' => $slot, 'events' => $polLout, 'kind' => 2];
            }
        }
        if (null !== $except) {
            foreach ($except as $slot) {
                $entries[] = ['slot' => $slot, 'events' => $polLerr | $polLhup | $polLpri, 'kind' => 3];
            }
        }

        $timeoutMs = -1;
        if ($seconds >= 0) {
            $timeoutMs = ($seconds * 1000) + (int) \floor($microseconds / 1000);
            if ($timeoutMs < 0) {
                $timeoutMs = 0;
            }
        }

        if ([] === $entries) {
            // No descriptors but at least one empty array was passed — timeout only.
            if ($timeoutMs > 0) {
                usleep($timeoutMs * 1000);
            }

            return [
                'read' => [],
                'write' => [],
                'except' => [],
                'count' => 0,
            ];
        }

        $pollEntries = [];
        foreach ($entries as $entry) {
            $pollEntries[] = ['fd' => $entry['slot']['fd'], 'events' => $entry['events']];
        }
        $revents = SocketsLibcThinAbi::poll($pollEntries, $timeoutMs);
        if (false === $revents) {
            self::recordError(null, SocketsLibcThinAbi::readErrno());

            return false;
        }

        $readyRead = [];
        $readyWrite = [];
        $readyExcept = [];
        $readyCount = 0;
        foreach ($revents as $i => $rev) {
            if (0 === $rev) {
                continue;
            }
            $entry = $entries[$i];
            $requested = $entry['events'];
            if (0 === ($rev & $requested) && 0 === ($rev & ($polLerr | $polLhup))) {
                continue;
            }
            match ($entry['kind']) {
                1 => $readyRead[] = $entry['slot'],
                2 => $readyWrite[] = $entry['slot'],
                3 => $readyExcept[] = $entry['slot'],
            };
            ++$readyCount;
        }

        return [
            'read' => $readyRead,
            'write' => $readyWrite,
            'except' => $readyExcept,
            'count' => $readyCount,
        ];
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
     * php-src: PHP_FUNCTION(socket_shutdown) (#6533).
     */
    public static function shutdown(ObjectEntry $object, int $how, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if (0 === SocketsLibcThinAbi::shutdown($fd, $how)) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return true;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        self::recordError($object, $errno);
        self::triggerWarning(
            $frame,
            \sprintf(
                'socket_shutdown(): Unable to shutdown socket [%d]: %s',
                $errno,
                SocketsLibcThinAbi::strerror($errno)
            )
        );

        return false;
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

        $object = VmSocket::wrapOwnedFd($fd, $ctx, $domain);
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $object;
    }

    /**
     * php-src: PHP_FUNCTION(socket_create_pair) — socketpair(2) as two Socket objects (#6563).
     *
     * @return array{0: ObjectEntry, 1: ObjectEntry}|false
     */
    public static function createPair(
        int $domain,
        int $type,
        int $protocol,
        \PHPCompiler\VM\Context $ctx
    ): array|false {
        // php-src PHP_FUNCTION(socket_create_pair) — same AF_* gate as socket_create (#30338).
        if (!\in_array($domain, [self::AF_UNIX, self::AF_INET, self::AF_INET6], true)) {
            throw new \ValueError(
                'socket_create_pair(): Argument #1 ($domain) must be one of AF_UNIX, AF_INET6, or AF_INET'
            );
        }
        if (!SocketsLibcThinAbi::available()) {
            return false;
        }
        $fds = SocketsLibcThinAbi::socketpair($domain, $type, $protocol);
        if (false === $fds) {
            self::recordError(null, SocketsLibcThinAbi::readErrno());

            return false;
        }

        $a = VmSocket::wrapOwnedFd($fds[0], $ctx, $domain);
        $b = VmSocket::wrapOwnedFd($fds[1], $ctx, $domain);
        VmSocket::clearErrorOptionalForLookupKey($a->id);
        VmSocket::clearErrorOptionalForLookupKey($b->id);

        return [$a, $b];
    }

    /**
     * php-src: PHP_FUNCTION(socket_create_listen) / php_open_listen_sock (#6212).
     *
     * AF_INET stream socket bound to INADDR_ANY, listening. Default backlog 128 on PHP 8.2.
     *
     * @return ObjectEntry|false
     */
    public static function createListen(int $port, int $backlog, \PHPCompiler\VM\Context $ctx, Frame $frame): ObjectEntry|false
    {
        // php-src ≤8.4: zend_long cast to unsigned short (PHP 8.5+ ValueError on range).
        $port = $port & 0xffff;
        if (!SocketsLibcThinAbi::available()) {
            return false;
        }

        $fd = SocketsLibcThinAbi::socket(self::AF_INET, SocketConstants::SOCK_STREAM, 0);
        if ($fd < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError(null, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_create_listen(): unable to create listening socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        if (0 !== SocketsLibcThinAbi::bindInet($fd, '0.0.0.0', $port)) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError(null, $errno);
            SocketsLibcThinAbi::close($fd);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_create_listen(): unable to bind to given address [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        if (0 !== SocketsLibcThinAbi::listen($fd, $backlog)) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError(null, $errno);
            SocketsLibcThinAbi::close($fd);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_create_listen(): unable to listen on socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        $object = VmSocket::wrapOwnedFd($fd, $ctx, self::AF_INET);
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $object;
    }

    public static function connect(ObjectEntry $object, string $addr, int $port, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $domain = VmSocket::domainForObject($object) ?? self::AF_INET;
        if (self::AF_UNIX === $domain) {
            if (\strlen($addr) >= SocketsLibcThinAbi::UNIX_PATH_MAX) {
                throw new \ValueError(
                    \sprintf(
                        'socket_connect(): Argument #2 ($address) must be less than %d',
                        SocketsLibcThinAbi::UNIX_PATH_MAX
                    )
                );
            }
            $rc = SocketsLibcThinAbi::connectUnix($fd, $addr);
        } elseif (self::AF_INET === $domain) {
            $rc = SocketsLibcThinAbi::connectInet($fd, $addr, $port);
        } else {
            throw new \ValueError(
                'socket_connect(): Argument #1 ($socket) must be one of AF_UNIX, AF_INET, or AF_INET6'
            );
        }
        if (0 === $rc) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return true;
        }
        $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
        if (null !== $hostErr) {
            self::recordError($object, $hostErr);
            VmSockets::triggerWarning(
                $frame,
                \sprintf(
                    'socket_connect(): Host lookup failed [%d]: %s',
                    $hostErr,
                    SocketsLibcThinAbi::strerror($hostErr)
                )
            );

            return false;
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
     * php-src: PHP_FUNCTION(socket_bind) — AF_INET / AF_UNIX bind(2) (#6176, #20268).
     */
    public static function bind(ObjectEntry $object, string $addr, int $port, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $domain = VmSocket::domainForObject($object) ?? self::AF_INET;
        if (self::AF_UNIX === $domain) {
            if (\strlen($addr) >= SocketsLibcThinAbi::UNIX_PATH_MAX) {
                throw new \ValueError(
                    \sprintf(
                        'socket_bind(): Argument #2 ($address) must be less than %d',
                        SocketsLibcThinAbi::UNIX_PATH_MAX
                    )
                );
            }
            $rc = SocketsLibcThinAbi::bindUnix($fd, $addr);
        } elseif (self::AF_INET === $domain) {
            $rc = SocketsLibcThinAbi::bindInet($fd, $addr, $port);
        } else {
            throw new \ValueError(
                'socket_bind(): Argument #1 ($socket) must be one of AF_UNIX, AF_INET, or AF_INET6'
            );
        }
        if (0 === $rc) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return true;
        }
        // php_set_inet_addr failure → PHP_SOCKET_ERROR(..., "Host lookup failed", ...) (#30315).
        $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
        if (null !== $hostErr) {
            self::recordError($object, $hostErr);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_bind(): Host lookup failed [%d]: %s',
                    $hostErr,
                    SocketsLibcThinAbi::strerror($hostErr)
                )
            );

            return false;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        self::recordError($object, $errno);
        self::triggerWarning(
            $frame,
            \sprintf(
                'socket_bind(): unable to bind [%d]: %s',
                $errno,
                SocketsLibcThinAbi::strerror($errno)
            )
        );

        return false;
    }

    /**
     * php-src: PHP_FUNCTION(socket_listen) (#6176).
     */
    public static function listen(ObjectEntry $object, int $backlog, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $rc = SocketsLibcThinAbi::listen($fd, $backlog);
        if (0 === $rc) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return true;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        self::recordError($object, $errno);
        self::triggerWarning(
            $frame,
            \sprintf(
                'socket_listen(): unable to listen [%d]: %s',
                $errno,
                SocketsLibcThinAbi::strerror($errno)
            )
        );

        return false;
    }

    /**
     * php-src: PHP_FUNCTION(socket_accept) (#6176).
     *
     * @return ObjectEntry|false
     */
    public static function accept(ObjectEntry $object, \PHPCompiler\VM\Context $ctx, Frame $frame): ObjectEntry|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $client = SocketsLibcThinAbi::accept($fd);
        if ($client < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_accept(): unable to accept [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }

        $parentDomain = VmSocket::domainForObject($object);
        $wrapped = VmSocket::wrapOwnedFd($client, $ctx, $parentDomain);
        VmSocket::clearErrorOptionalForLookupKey($wrapped->id);

        return $wrapped;
    }

    /** Linux SOL_SOCKET SO_RCVTIMEO / SO_SNDTIMEO / SO_LINGER */
    private const SO_LINGER = 13;
    private const SO_RCVTIMEO = 20;
    private const SO_SNDTIMEO = 21;

    /**
     * php-src: PHP_FUNCTION(socket_set_option) — int/timeval/linger (#6176).
     *
     * @param int|array{sec?:int,usec?:int,l_onoff?:int,l_linger?:int} $value
     */
    public static function setOption(ObjectEntry $object, int $level, int $option, int|array $value, Frame $frame): bool
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        if (self::SO_RCVTIMEO === $option || self::SO_SNDTIMEO === $option) {
            if (!\is_array($value)) {
                throw new \TypeError(
                    'socket_set_option(): Argument #4 ($value) must be of type array when option is SO_RCVTIMEO or SO_SNDTIMEO, '
                    .\get_debug_type($value).' given'
                );
            }
            if (!\array_key_exists('sec', $value)) {
                throw new \ValueError('socket_set_option(): Argument #4 ($value) must have key "sec"');
            }
            if (!\array_key_exists('usec', $value)) {
                throw new \ValueError('socket_set_option(): Argument #4 ($value) must have key "usec"');
            }
            $sec = (int) $value['sec'];
            $usec = (int) $value['usec'];
            $rc = SocketsLibcThinAbi::setsockoptTimeval($fd, $level, $option, $sec, $usec);
        } elseif (self::SO_LINGER === $option) {
            if (!\is_array($value)) {
                throw new \TypeError(
                    'socket_set_option(): Argument #4 ($value) must be of type array when option is SO_LINGER, '
                    .\get_debug_type($value).' given'
                );
            }
            if (!\array_key_exists('l_onoff', $value)) {
                throw new \ValueError('socket_set_option(): Argument #4 ($value) must have key "l_onoff"');
            }
            if (!\array_key_exists('l_linger', $value)) {
                throw new \ValueError('socket_set_option(): Argument #4 ($value) must have key "l_linger"');
            }
            $onoff = (int) $value['l_onoff'];
            $linger = (int) $value['l_linger'];
            $rc = SocketsLibcThinAbi::setsockoptLinger($fd, $level, $option, $onoff, $linger);
        } else {
            if (\is_array($value)) {
                throw new \TypeError(
                    'socket_set_option(): Argument #4 ($value) must be of type int, array given'
                );
            }
            $rc = SocketsLibcThinAbi::setsockoptInt($fd, $level, $option, $value);
        }

        if (0 === $rc) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return true;
        }
        $errno = SocketsLibcThinAbi::readErrno();
        self::recordError($object, $errno);
        self::triggerWarning(
            $frame,
            \sprintf(
                'socket_set_option(): unable to set socket option [%d]: %s',
                $errno,
                SocketsLibcThinAbi::strerror($errno)
            )
        );

        return false;
    }

    /**
     * php-src: PHP_FUNCTION(socket_get_option) (#6176).
     *
     * @return int|array{sec:int,usec:int}|array{l_onoff:int,l_linger:int}|false
     */
    public static function getOption(ObjectEntry $object, int $level, int $option, Frame $frame): int|array|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }

        if (self::SO_RCVTIMEO === $option || self::SO_SNDTIMEO === $option) {
            $tv = SocketsLibcThinAbi::getsockoptTimeval($fd, $level, $option);
            if (false === $tv) {
                $errno = SocketsLibcThinAbi::readErrno();
                self::recordError($object, $errno);
                self::triggerWarning(
                    $frame,
                    \sprintf(
                        'socket_get_option(): unable to get socket option [%d]: %s',
                        $errno,
                        SocketsLibcThinAbi::strerror($errno)
                    )
                );

                return false;
            }
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return ['sec' => $tv[0], 'usec' => $tv[1]];
        }
        if (self::SO_LINGER === $option) {
            $lg = SocketsLibcThinAbi::getsockoptLinger($fd, $level, $option);
            if (false === $lg) {
                $errno = SocketsLibcThinAbi::readErrno();
                self::recordError($object, $errno);
                self::triggerWarning(
                    $frame,
                    \sprintf(
                        'socket_get_option(): unable to get socket option [%d]: %s',
                        $errno,
                        SocketsLibcThinAbi::strerror($errno)
                    )
                );

                return false;
            }
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return ['l_onoff' => $lg[0], 'l_linger' => $lg[1]];
        }

        $val = SocketsLibcThinAbi::getsockoptInt($fd, $level, $option);
        if (false === $val) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_get_option(): unable to get socket option [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $val;
    }

    /**
     * @return int|false bytes written
     */
    public static function write(ObjectEntry $object, string $buf, ?int $length, Frame $frame): int|false
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
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_write(): unable to write to socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $n;
    }

    /**
     * php-src: PHP_FUNCTION(socket_send) — connected send(2) with flags (#20238).
     *
     * @return int|false bytes written
     */
    public static function send(
        ObjectEntry $object,
        string $buf,
        int $length,
        int $flags,
        Frame $frame
    ): int|false {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if ($length < 0) {
            throw new \ValueError('socket_send(): Argument #3 ($length) must be greater than or equal to 0');
        }
        if ($length > \strlen($buf)) {
            $length = \strlen($buf);
        }
        $n = SocketsLibcThinAbi::send($fd, $buf, $length, $flags);
        if ($n < 0) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_send(): Unable to write to socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $n;
    }

    /**
     * php-src: PHP_FUNCTION(socket_recv) — connected recv(2) with flags (#20238).
     *
     * Returns false on error (caller assigns null to &$data and returns false).
     * Returns array{0: ?string, 1: int} on success — null data means EOF (0 bytes).
     *
     * @return array{0: ?string, 1: int}|false
     */
    public static function recv(
        ObjectEntry $object,
        int $length,
        int $flags,
        Frame $frame
    ): array|false {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        // php-src: if ((len + 1) < 2) RETURN_FALSE; — length < 1
        if ($length < 1) {
            return false;
        }
        $data = SocketsLibcThinAbi::recv($fd, $length, $flags);
        if (false === $data) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_recv(): Unable to read from socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);
        if ('' === $data) {
            return [null, 0];
        }

        return [$data, \strlen($data)];
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
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $data;
    }

    public static function close(ObjectEntry $object): void
    {
        // Exported streams share the Socket fd (#22542) — fclose first to avoid double close(2).
        $exportHandle = VmSocket::existingStreamHandleForObject($object);
        if (null !== $exportHandle) {
            VmFs::fclose($exportHandle);
            VmSocket::release($object);
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return;
        }
        $fd = VmSocket::ownedFdForObject($object);
        if (null !== $fd) {
            SocketsLibcThinAbi::close($fd);
        }
        VmSocket::release($object);
        VmSocket::clearErrorOptionalForLookupKey($object->id);
    }

    /** socket_close() for JIT/AOT object handles (ptrToInt) — peer {@see close()} (#27394). */
    public static function closeForLookupKey(int $key): void
    {
        if ($key <= 0) {
            return;
        }
        $exportHandle = VmSocket::existingStreamHandleForLookupKey($key);
        if (null !== $exportHandle) {
            VmFs::fclose($exportHandle);
            VmSocket::releaseForLookupKey($key);
            VmSocket::clearErrorOptionalForLookupKey($key);

            return;
        }
        $fd = VmSocket::ownedFdForLookupKey($key);
        if (null !== $fd) {
            SocketsLibcThinAbi::close($fd);
        }
        VmSocket::releaseForLookupKey($key);
        VmSocket::clearErrorOptionalForLookupKey($key);
    }

    /** Record libc errno after a failed socket(2) under NestedJIT (#27394). */
    public static function recordLibcErrno(?ObjectEntry $object = null): void
    {
        self::recordError($object, SocketsLibcThinAbi::readErrno());
    }

    public static function lastError(?ObjectEntry $object = null): int
    {
        if (null !== $object) {
            return VmSocket::lastErrorForLookupKey($object->id);
        }

        return VmSocket::lastErrorForLookupKey(0);
    }

    public static function clearError(?ObjectEntry $object = null): void
    {
        if (null !== $object) {
            VmSocket::clearErrorOptionalForLookupKey($object->id);

            return;
        }
        VmSocket::clearErrorOptionalForLookupKey(0);
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
     * php-src: PHP_FUNCTION(socket_getsockname) — AF_INET (#6248).
     *
     * @return array{0: string, 1: int}|false
     */
    public static function getsockname(ObjectEntry $object, Frame $frame): array|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $name = SocketsLibcThinAbi::getsocknameInet($fd);
        if (false === $name) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_getsockname(): unable to retrieve socket name [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $name;
    }

    /**
     * php-src: PHP_FUNCTION(socket_getpeername) — AF_INET (#6248).
     *
     * @return array{0: string, 1: int}|false
     */
    public static function getpeername(ObjectEntry $object, Frame $frame): array|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        $name = SocketsLibcThinAbi::getpeernameInet($fd);
        if (false === $name) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_getpeername(): unable to retrieve peer name [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $name;
    }

    /**
     * php-src: PHP_FUNCTION(socket_sendto) — AF_INET (#6248).
     */
    public static function sendto(
        ObjectEntry $object,
        string $data,
        int $length,
        int $flags,
        string $addr,
        int $port,
        Frame $frame
    ): int|false {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if ($length < 0) {
            $length = 0;
        }
        if ($length > \strlen($data)) {
            $length = \strlen($data);
        }
        $n = SocketsLibcThinAbi::sendtoInet($fd, $data, $length, $flags, $addr, $port);
        if ($n < 0) {
            $hostErr = SocketsLibcThinAbi::consumeHostLookupError();
            if (null !== $hostErr) {
                self::recordError($object, $hostErr);
                self::triggerWarning(
                    $frame,
                    \sprintf(
                        'socket_sendto(): Host lookup failed [%d]: %s',
                        $hostErr,
                        SocketsLibcThinAbi::strerror($hostErr)
                    )
                );

                return false;
            }
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_sendto(): unable to write to socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $n;
    }

    /**
     * php-src: PHP_FUNCTION(socket_recvfrom) — AF_INET (#6248).
     *
     * @return array{0: string, 1: string, 2: int}|false
     */
    public static function recvfrom(ObjectEntry $object, int $length, int $flags, Frame $frame): array|false
    {
        $fd = VmSocket::fdForObject($object);
        if (null === $fd) {
            return false;
        }
        if ($length < 0) {
            $length = 0;
        }
        $got = SocketsLibcThinAbi::recvfromInet($fd, $length, $flags);
        if (false === $got) {
            $errno = SocketsLibcThinAbi::readErrno();
            self::recordError($object, $errno);
            self::triggerWarning(
                $frame,
                \sprintf(
                    'socket_recvfrom(): unable to read from socket [%d]: %s',
                    $errno,
                    SocketsLibcThinAbi::strerror($errno)
                )
            );

            return false;
        }
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $got;
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

    /** @internal shared with {@see VmSocketAddrinfo} */
    public static function recordError(?ObjectEntry $object, int $errno): void
    {
        VmSocket::recordErrorForLookupKey(null !== $object ? $object->id : 0, $errno);
    }

    /** NestedJIT connect/bind — no ObjectEntry, key is object address (#31240 / #31270). */
    public static function recordErrorForLookupKey(int $key, int $errno): void
    {
        VmSocket::recordErrorForLookupKey($key, $errno);
    }

    public static function clearErrorForLookupKey(int $key): void
    {
        VmSocket::clearErrorForLookupKey($key);
    }

    /** NestedJIT socket_last_error — key≤0 → process errno (#31270). */
    public static function lastErrorForLookupKey(int $key): int
    {
        return VmSocket::lastErrorForLookupKey($key);
    }

    /**
     * NestedJIT socket_clear_error — matches {@see clearError} (socket-only vs process-only).
     */
    public static function clearErrorOptionalForLookupKey(int $key): void
    {
        VmSocket::clearErrorOptionalForLookupKey($key);
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
        VmSocket::clearErrorOptionalForLookupKey($object->id);

        return $out;
    }
}
