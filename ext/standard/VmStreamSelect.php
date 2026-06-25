<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_select() via libc poll(2) on VmPhpFdStream fds — no host stream delegation when fds available (#9216).
 *
 * Falls back to {@see VmStreamSelectPure} for adopted host streams or when FFI is disabled.
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_select)
 */
final class VmStreamSelect
{
    private const POLLIN = 0x0001;

    private const POLLOUT = 0x0004;

    private const POLLPRI = 0x0002;

    private const POLLERR = 0x0008;

    private const POLLHUP = 0x0010;

    private const POLLNVAL = 0x0020;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmStreamSelectPure::available();
    }

    /**
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     */
    public static function multiplex(
        array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds,
    ): int|false {
        if (self::canNativePoll($read, $write, $except) && null !== self::ffi()) {
            $result = self::nativePoll($read, $write, $except, $seconds, $microseconds);
            if (false !== $result) {
                return $result;
            }
        }

        return VmStreamSelectPure::multiplex($read, $write, $except, $seconds, $microseconds);
    }

    /**
     * @return list<StreamSelectPair>
     */
    public static function pairsFromArray(Variable $arrayVar): array
    {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            return [];
        }
        $pairs = [];
        foreach ($arrayVar->toArray()->iterateKeyed(true) as $pair) {
            [, $streamVar] = $pair;
            $streamVar = $streamVar->resolveIndirect();
            if (!$streamVar->isStreamResource()) {
                continue;
            }
            $handle = ResourceSupport::resolveHandle($streamVar);
            if (null === $handle) {
                continue;
            }
            if (VmPhpFdStream::isValidHandle($handle)) {
                $fd = VmPhpFdStream::fdForHandle($handle);
                if (null === $fd) {
                    continue;
                }
                $pairs[] = new StreamSelectPair($handle, $fd, null);

                continue;
            }
            $host = VmFs::lookupResource($handle);
            if (!\is_resource($host)) {
                continue;
            }
            $pairs[] = new StreamSelectPair($handle, null, $host);
        }

        return $pairs;
    }

    public static function writeBackStreamArray(Variable $targetVar, array $readyHandles, \PHPCompiler\VM\Context $ctx): void
    {
        $targetVar = $targetVar->resolveIndirect();
        $ht = new HashTable();
        $index = 0;
        foreach ($readyHandles as $handle) {
            if (!\is_int($handle)) {
                continue;
            }
            $slot = new Variable();
            $slot->streamHandle($handle, $ctx);
            $ht->addIndex($index, $slot);
            ++$index;
        }
        $replacement = new Variable();
        $replacement->array($ht);
        $targetVar->copyFrom($replacement);
    }

    /**
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     */
    private static function canNativePoll(array $read, ?array $write, ?array $except): bool
    {
        foreach ([$read, $write ?? [], $except ?? []] as $group) {
            foreach ($group as $pair) {
                if (!$pair instanceof StreamSelectPair || null === $pair->fd) {
                    return false;
                }
            }
        }

        return [] !== $read || [] !== ($write ?? []) || [] !== ($except ?? []);
    }

    /**
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     */
    private static function nativePoll(
        array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds,
    ): int|false {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        /** @var array<int, array{events: int, read: list<int>, write: list<int>, except: list<int>}> */
        $fdMap = [];

        $register = static function (StreamSelectPair $pair, int $event, string $bucket) use (&$fdMap): void {
            if (null === $pair->fd) {
                return;
            }
            $fd = $pair->fd;
            if (!isset($fdMap[$fd])) {
                $fdMap[$fd] = ['events' => 0, 'read' => [], 'write' => [], 'except' => []];
            }
            $fdMap[$fd]['events'] |= $event;
            $fdMap[$fd][$bucket][] = $pair->handle;
        };

        foreach ($read as $pair) {
            $register($pair, self::POLLIN, 'read');
        }
        if (null !== $write) {
            foreach ($write as $pair) {
                $register($pair, self::POLLOUT, 'write');
            }
        }
        if (null !== $except) {
            foreach ($except as $pair) {
                $register($pair, self::POLLPRI, 'except');
            }
        }

        if ([] === $fdMap) {
            return 0;
        }

        $count = \count($fdMap);
        $pollfds = $ffi->new("struct pollfd[$count]");
        $i = 0;
        $fdOrder = [];
        foreach ($fdMap as $fd => $meta) {
            $fdOrder[] = $fd;
            $pollfds[$i]->fd = $fd;
            $pollfds[$i]->events = $meta['events'];
            $pollfds[$i]->revents = 0;
            ++$i;
        }

        $timeoutMs = -1;
        if ($seconds >= 0) {
            $timeoutMs = ($seconds * 1000) + (int) \intdiv($microseconds, 1000);
        }

        $rc = (int) $ffi->poll($pollfds, $count, $timeoutMs);
        if ($rc < 0) {
            return false;
        }

        $readyRead = [];
        $readyWrite = [];
        $readyExcept = [];
        $readyMask = self::POLLIN | self::POLLPRI | self::POLLERR | self::POLLHUP;

        foreach ($fdOrder as $idx => $fd) {
            $revents = (int) $pollfds[$idx]->revents;
            if (0 === $revents) {
                continue;
            }
            $meta = $fdMap[$fd];
            if (0 !== ($revents & $readyMask)) {
                foreach ($meta['read'] as $handle) {
                    $readyRead[$handle] = $handle;
                }
                foreach ($meta['except'] as $handle) {
                    $readyExcept[$handle] = $handle;
                }
            }
            if (0 !== ($revents & (self::POLLOUT | self::POLLERR | self::POLLHUP))) {
                foreach ($meta['write'] as $handle) {
                    $readyWrite[$handle] = $handle;
                }
            }
        }

        $read = self::filterPairsByHandles($read, $readyRead);
        if (null !== $write) {
            $write = self::filterPairsByHandles($write, $readyWrite);
        }
        if (null !== $except) {
            $except = self::filterPairsByHandles($except, $readyExcept);
        }

        return $rc;
    }

    /**
     * @param list<StreamSelectPair> $pairs
     * @param array<int, int> $readyHandles
     *
     * @return list<StreamSelectPair>
     */
    private static function filterPairsByHandles(array $pairs, array $readyHandles): array
    {
        if ([] === $readyHandles) {
            return [];
        }
        $filtered = [];
        foreach ($pairs as $pair) {
            if (isset($readyHandles[$pair->handle])) {
                $filtered[] = $pair;
            }
        }

        return $filtered;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned long int nfds_t;

struct pollfd {
    int fd;
    short events;
    short revents;
};

int poll(struct pollfd *fds, nfds_t nfds, int timeout);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}

/**
 * @internal stream_select pair — VM handle plus native fd or host bootstrap resource.
 */
final class StreamSelectPair
{
    public function __construct(
        public readonly int $handle,
        public readonly ?int $fd,
        public readonly mixed $host,
    ) {
    }
}
