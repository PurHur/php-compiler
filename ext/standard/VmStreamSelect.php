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
        try {
            return VmStreamSelectPure::multiplex($read, $write, $except, $seconds, $microseconds);
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
            if (VmPhpFdStream::isValidHandle($handle)) {
                $fd = VmPhpFdStream::fdForHandle($handle);
                if (null === $fd) {
                    continue;
                }
                // proc_open leaves the child SIGSTOP'd until pipe I/O; selecting is wait-for-I/O
                // and must resume so poll(2) can observe readiness (#19686, php-src proc_open.c).
                VmProcessProcOpenNative::resumeChildForPipeHandle($handle);
                $pairs[] = new StreamSelectPair($handle, $fd, null);

                continue;
            }
            // php://temp: prefer Pure/FFI mkstemp fd; host tmpfile() only as fallback (#19688/#19691).
            if (VmPhpMemoryStream::isValidHandle($handle)) {
                $castFd = VmPhpMemoryStream::castFdForSelect($handle);
                if (null !== $castFd) {
                    $pairs[] = new StreamSelectPair($handle, $castFd, null, true);

                    continue;
                }
                $castHost = VmPhpMemoryStream::castHostResourceForSelect($handle);
                if (\is_resource($castHost)) {
                    $pairs[] = new StreamSelectPair($handle, null, $castHost, true);
                }

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
    ) {
    }
}
