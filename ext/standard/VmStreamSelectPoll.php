<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc poll(2) for VmPhpFdStream handles in stream_select() (#14758, #9216).
 *
 * php-src: ext/standard/streams.c — php_stream_select()
 */
final class VmStreamSelectPoll
{
    private const POLLIN = 0x001;

    private const POLLOUT = 0x004;

    private const POLLERR = 0x008;

    private const POLLHUP = 0x010;

    private const POLLPRI = 0x002;

    private const KIND_READ = 1;

    private const KIND_WRITE = 2;

    private const KIND_EXCEPT = 3;

    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     *
     * @return array{read: list<StreamSelectPair>, write: list<StreamSelectPair>, except: list<StreamSelectPair>, count: int}|false
     */
    public static function multiplexFdPairs(
        array $read,
        ?array $write,
        ?array $except,
        int $timeoutMs,
    ): array|false {
        /** @var list<array{pair: StreamSelectPair, events: int, kind: int}> $entries */
        $entries = [];
        foreach ($read as $pair) {
            if (null !== $pair->fd) {
                $entries[] = ['pair' => $pair, 'events' => self::POLLIN | self::POLLHUP, 'kind' => self::KIND_READ];
            }
        }
        if (null !== $write) {
            foreach ($write as $pair) {
                if (null !== $pair->fd) {
                    $entries[] = ['pair' => $pair, 'events' => self::POLLOUT, 'kind' => self::KIND_WRITE];
                }
            }
        }
        if (null !== $except) {
            foreach ($except as $pair) {
                if (null !== $pair->fd) {
                    $entries[] = ['pair' => $pair, 'events' => self::POLLERR | self::POLLHUP | self::POLLPRI, 'kind' => self::KIND_EXCEPT];
                }
            }
        }

        if ([] === $entries) {
            return ['read' => [], 'write' => [], 'except' => [], 'count' => 0];
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $count = \count($entries);
        $pollfd = $ffi->new('struct pollfd['.$count.']');
        for ($i = 0; $i < $count; ++$i) {
            $pollfd[$i]->fd = $entries[$i]['pair']->fd;
            $pollfd[$i]->events = $entries[$i]['events'];
            $pollfd[$i]->revents = 0;
        }

        $rc = (int) $ffi->poll($ffi->cast('struct pollfd*', $pollfd), $count, $timeoutMs);
        if ($rc < 0) {
            return false;
        }

        $readyRead = [];
        $readyWrite = [];
        $readyExcept = [];
        $readyCount = 0;

        for ($i = 0; $i < $count; ++$i) {
            $revents = (int) $pollfd[$i]->revents;
            if (0 === $revents) {
                continue;
            }
            $entry = $entries[$i];
            $requested = $entry['events'];
            if (0 === ($revents & $requested) && 0 === ($revents & (self::POLLERR | self::POLLHUP))) {
                continue;
            }
            match ($entry['kind']) {
                self::KIND_READ => $readyRead[] = $entry['pair'],
                self::KIND_WRITE => $readyWrite[] = $entry['pair'],
                self::KIND_EXCEPT => $readyExcept[] = $entry['pair'],
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

    private static function ffi(): ?\FFI
    {
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!VmPhpFdStream::available()) {
            self::$unavailable = true;

            return null;
        }
        if (!\extension_loaded('ffi')) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
struct pollfd {
    int fd;
    short events;
    short revents;
};
typedef unsigned long nfds_t;
int poll(struct pollfd *fds, nfds_t nfds, int timeout);
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
