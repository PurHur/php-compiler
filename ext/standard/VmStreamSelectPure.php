<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_select() bootstrap via host stream_select and libc poll for native fds (#9216, #14758).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_select)
 */
final class VmStreamSelectPure
{
    public static function available(): bool
    {
        return \function_exists('stream_select') || VmStreamSelectPoll::available();
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
        if (!self::available()) {
            return false;
        }

        $deadline = self::deadlineFromTimeout($seconds, $microseconds);

        [$readFd, $readHost] = self::partitionPairs($read);
        [$writeFd, $writeHost] = self::partitionPairs($write ?? []);
        [$exceptFd, $exceptHost] = self::partitionPairs($except ?? []);

        $readyCount = 0;
        $readyRead = [];
        $readyWrite = [];
        $readyExcept = [];

        if ([] !== $readFd || [] !== $writeFd || [] !== $exceptFd) {
            $polled = VmStreamSelectPoll::multiplexFdPairs(
                $readFd,
                [] === $writeFd ? null : $writeFd,
                [] === $exceptFd ? null : $exceptFd,
                self::remainingTimeoutMs($deadline),
            );
            if (false === $polled) {
                return false;
            }
            $readyRead = array_merge($readyRead, $polled['read']);
            $readyWrite = array_merge($readyWrite, $polled['write']);
            $readyExcept = array_merge($readyExcept, $polled['except']);
            $readyCount += $polled['count'];
        }

        if ([] !== $readHost || [] !== $writeHost || [] !== $exceptHost) {
            if (!\function_exists('stream_select')) {
                return false;
            }
            $remaining = self::remainingTimeout($deadline);
            $hostReady = self::multiplexHostPairs(
                $readHost,
                [] === $writeHost ? null : $writeHost,
                [] === $exceptHost ? null : $exceptHost,
                $remaining['seconds'],
                $remaining['microseconds'],
            );
            if (false === $hostReady) {
                return false;
            }
            $readyRead = array_merge($readyRead, $hostReady['read']);
            $readyWrite = array_merge($readyWrite, $hostReady['write']);
            $readyExcept = array_merge($readyExcept, $hostReady['except']);
            $readyCount += $hostReady['count'];
        }

        $read = $readyRead;
        $write = null === $write ? null : $readyWrite;
        $except = null === $except ? null : $readyExcept;

        return $readyCount;
    }

    /**
     * @param list<StreamSelectPair> $pairs
     *
     * @return array{0: list<StreamSelectPair>, 1: list<StreamSelectPair>}
     */
    private static function partitionPairs(array $pairs): array
    {
        $fdPairs = [];
        $hostPairs = [];
        foreach ($pairs as $pair) {
            if (null !== $pair->fd) {
                $fdPairs[] = $pair;
            } elseif (\is_resource($pair->host)) {
                $hostPairs[] = $pair;
            }
        }

        return [$fdPairs, $hostPairs];
    }

    /**
     * @param list<StreamSelectPair> $read
     * @param list<StreamSelectPair>|null $write
     * @param list<StreamSelectPair>|null $except
     *
     * @return array{read: list<StreamSelectPair>, write: list<StreamSelectPair>, except: list<StreamSelectPair>, count: int}|false
     */
    private static function multiplexHostPairs(
        array $read,
        ?array $write,
        ?array $except,
        int $seconds,
        int $microseconds,
    ): array|false {
        $readHosts = self::hostResources($read);
        $writeHosts = null === $write ? null : self::hostResources($write);
        $exceptHosts = null === $except ? null : self::hostResources($except);

        $ready = @\stream_select($readHosts, $writeHosts, $exceptHosts, $seconds, $microseconds);
        if (false === $ready) {
            return false;
        }

        $readyRead = self::pairsForReadyHosts($read, $readHosts);
        $readyWrite = null === $write || null === $writeHosts
            ? []
            : self::pairsForReadyHosts($write, $writeHosts);
        $readyExcept = null === $except || null === $exceptHosts
            ? []
            : self::pairsForReadyHosts($except, $exceptHosts);

        return [
            'read' => $readyRead,
            'write' => $readyWrite,
            'except' => $readyExcept,
            'count' => $ready,
        ];
    }

    /**
     * @param list<StreamSelectPair> $pairs
     *
     * @return list<resource>
     */
    private static function hostResources(array $pairs): array
    {
        $hosts = [];
        foreach ($pairs as $pair) {
            if (\is_resource($pair->host)) {
                $hosts[] = $pair->host;
            }
        }

        return $hosts;
    }

    /**
     * @param list<StreamSelectPair> $pairs
     * @param list<resource> $readyHosts
     *
     * @return list<StreamSelectPair>
     */
    private static function pairsForReadyHosts(array $pairs, array $readyHosts): array
    {
        if ([] === $readyHosts) {
            return [];
        }
        $readySet = [];
        foreach ($readyHosts as $host) {
            if (\is_resource($host)) {
                $readySet[\get_resource_id($host)] = true;
            }
        }
        $filtered = [];
        foreach ($pairs as $pair) {
            if (\is_resource($pair->host) && isset($readySet[\get_resource_id($pair->host)])) {
                $filtered[] = $pair;
            }
        }

        return $filtered;
    }

    private static function deadlineFromTimeout(int $seconds, int $microseconds): float
    {
        return microtime(true) + max(0, $seconds) + max(0, $microseconds) / 1_000_000;
    }

    private static function remainingTimeoutMs(float $deadline): int
    {
        $remaining = (int) ceil(($deadline - microtime(true)) * 1000);

        return max(0, $remaining);
    }

    /** @return array{seconds: int, microseconds: int} */
    private static function remainingTimeout(float $deadline): array
    {
        $remainingUs = (int) max(0, ($deadline - microtime(true)) * 1_000_000);

        return [
            'seconds' => intdiv($remainingUs, 1_000_000),
            'microseconds' => $remainingUs % 1_000_000,
        ];
    }
}
