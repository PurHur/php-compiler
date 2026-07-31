<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_select() — delegates to {@see VmStreamSelectPure} (#9216, #12662).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_select)
 */
final class VmStreamSelect
{
    public static function available(): bool
    {
        return VmStreamSelectPure::available();
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
        $ephemeral = self::collectEphemeralCasts($read, $write, $except);
        // Snapshot before Pure mutates lists to the ready subset (from_fd_set casts all; #26000).
        $origRead = $read;
        $origWrite = $write;
        $origExcept = $except;
        try {
            $ready = VmStreamSelectPure::multiplex($read, $write, $except, $seconds, $microseconds);
            if (false !== $ready) {
                // php-src stream_array_from_fd_set re-casts every stream after select (#26000).
                self::recastUserStreamsAfterSelect($origRead);
                if (null !== $origWrite) {
                    self::recastUserStreamsAfterSelect($origWrite);
                }
                if (null !== $origExcept) {
                    self::recastUserStreamsAfterSelect($origExcept);
                }
            }

            return $ready;
        } finally {
            self::releaseEphemeralPairList($ephemeral);
        }
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
            $resolved = self::pairForHandle($handle, $handle, 0);
            if (null !== $resolved) {
                $pairs[] = $resolved;
            }
        }

        return $pairs;
    }

    /**
     * Resolve a VM stream handle to a selectable pair (php-src stream_array_to_fd_set).
     *
     * User wrappers invoke {@see VmUserStream::cast} with STREAM_CAST_FOR_SELECT (#26000).
     * $writeBackHandle is the original array member (user stream), which may differ from the
     * cast-target handle used for poll/host select.
     */
    private static function pairForHandle(int $writeBackHandle, int $handle, int $castDepth): ?StreamSelectPair
    {
        if ($castDepth > 8) {
            return null;
        }
        if (VmUserStream::isValidHandle($handle)) {
            // php-src userspace.c php_userstreamop_cast — STREAM_CAST_FOR_SELECT (3).
            $castHandle = VmUserStream::cast($handle, StdlibConstants::STREAM_CAST_FOR_SELECT);
            if (null === $castHandle) {
                return null;
            }
            $pair = self::pairForHandle($writeBackHandle, $castHandle, $castDepth + 1);
            if (null === $pair) {
                return null;
            }
            // Preserve write-back handle + mark for from_fd_set recast (#26000).
            return new StreamSelectPair(
                $writeBackHandle,
                $pair->fd,
                $pair->host,
                $pair->ephemeralCast,
                true,
            );
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $fd = VmPhpFdStream::fdForHandle($handle);
            if (null === $fd) {
                return null;
            }
            // proc_open leaves the child SIGSTOP'd until pipe I/O; selecting is wait-for-I/O
            // and must resume so poll(2) can observe readiness (#19686, php-src proc_open.c).
            VmProcessProcOpenNative::resumeChildForPipeHandle($handle);

            return new StreamSelectPair($writeBackHandle, $fd, null);
        }
        // php://temp: prefer Pure/FFI mkstemp fd; host tmpfile() only as fallback (#19688/#19691).
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $castFd = VmPhpMemoryStream::castFdForSelect($handle);
            if (null !== $castFd) {
                return new StreamSelectPair($writeBackHandle, $castFd, null, true);
            }
            $castHost = VmPhpMemoryStream::castHostResourceForSelect($handle);
            if (\is_resource($castHost)) {
                return new StreamSelectPair($writeBackHandle, null, $castHost, true);
            }

            return null;
        }
        $host = VmFs::lookupResource($handle);
        if (!\is_resource($host)) {
            return null;
        }

        return new StreamSelectPair($writeBackHandle, null, $host);
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
     *
     * @return list<StreamSelectPair>
     */
    private static function collectEphemeralCasts(array $read, ?array $write, ?array $except): array
    {
        $out = [];
        foreach ($read as $pair) {
            if ($pair->ephemeralCast) {
                $out[] = $pair;
            }
        }
        if (null !== $write) {
            foreach ($write as $pair) {
                if ($pair->ephemeralCast) {
                    $out[] = $pair;
                }
            }
        }
        if (null !== $except) {
            foreach ($except as $pair) {
                if ($pair->ephemeralCast) {
                    $out[] = $pair;
                }
            }
        }

        return $out;
    }

    /** @param list<StreamSelectPair> $pairs */
    private static function releaseEphemeralPairList(array $pairs): void
    {
        foreach ($pairs as $pair) {
            if (!$pair->ephemeralCast) {
                continue;
            }
            if (null !== $pair->fd) {
                VmPhpFdStream::closeRawFd($pair->fd);
            }
            if (\is_resource($pair->host)) {
                @\fclose($pair->host);
            }
        }
    }

    /**
     * php-src stream_array_from_fd_set casts again after select; re-invoke userspace stream_cast
     * for pairs that were resolved via wrapper cast (#26000).
     *
     * @param list<StreamSelectPair> $pairs
     */
    private static function recastUserStreamsAfterSelect(array $pairs): void
    {
        foreach ($pairs as $pair) {
            if (!$pair->userCastRecast) {
                continue;
            }
            if (!VmUserStream::isValidHandle($pair->handle)) {
                continue;
            }
            VmUserStream::cast($pair->handle, StdlibConstants::STREAM_CAST_FOR_SELECT);
        }
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
        public readonly bool $ephemeralCast = false,
        /** Re-invoke userspace stream_cast after select (php-src stream_array_from_fd_set; #26000). */
        public readonly bool $userCastRecast = false,
    ) {
    }
}
