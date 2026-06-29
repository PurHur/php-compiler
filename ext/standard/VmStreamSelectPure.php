<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_select() bootstrap via host stream_select when libc poll unavailable (#9216).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_select)
 */
final class VmStreamSelectPure
{
    public static function available(): bool
    {
        return \function_exists('stream_select');
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

        $readHosts = self::hostResources($read);
        $writeHosts = null === $write ? null : self::hostResources($write);
        $exceptHosts = null === $except ? null : self::hostResources($except);

        $ready = @\stream_select($readHosts, $writeHosts, $exceptHosts, $seconds, $microseconds);
        if (false === $ready) {
            return false;
        }

        $read = self::pairsForReadyHosts($read, $readHosts);
        if (null !== $write && null !== $writeHosts) {
            $write = self::pairsForReadyHosts($write, $writeHosts);
        }
        if (null !== $except && null !== $exceptHosts) {
            $except = self::pairsForReadyHosts($except, $exceptHosts);
        }

        return $ready;
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
}
