<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\sockets\VmSockets;
use PHPCompiler\VM\Variable;

/**
 * stream_socket_client without libc socket FFI (#8953, #12858; pairs {@see VmStreamSocketNative}).
 *
 * Bootstrap path when FFI is disabled: host stream_socket_client under Zend VM.
 *
 * php-src: ext/standard/streamsfuncs.c — stream_socket_client
 */
final class VmStreamSocketPure
{
    public static function available(): bool
    {
        return \function_exists('stream_socket_client');
    }

    /**
     * @return array{0: int|false, 1: int, 2: string, 3: ?int}
     */
    public static function client(
        string $remote,
        float $timeout,
        int $flags,
        ?Variable $contextVar = null
    ): array {
        if (str_contains($remote, "\0")) {
            return [false, 0, 'Unable to parse remote socket path', null];
        }

        $parsed = self::parseRemoteSocket($remote);
        if (null === $parsed) {
            return [false, 0, 'Unable to parse remote socket path', null];
        }

        if ('ssl' === $parsed['transport'] || 'tls' === $parsed['transport']) {
            return [false, 0, 'ssl:// transport is not supported in this compiler build', null];
        }

        if ('unix' === $parsed['transport']) {
            return [false, 0, 'unix:// transport is not supported in this compiler build', null];
        }

        $contextTimeout = self::connectTimeoutFromContext($contextVar);
        if (null !== $contextTimeout) {
            $timeout = $contextTimeout;
        }

        $errno = 0;
        $errstr = '';
        $sock = @\stream_socket_client($remote, $errno, $errstr, $timeout, $flags);
        if (false === $sock) {
            return [false, $errno, '' !== $errstr ? $errstr : 'Connection refused', null];
        }

        $handle = VmFs::adoptStreamResource($sock, $remote);
        if (false === $handle) {
            @\fclose($sock);

            return [false, 0, 'Unable to create stream from socket', null];
        }

        return [$handle, 0, '', null];
    }

    /**
     * @return array{0: int|false, 1: int, 2: string, 3: ?int}
     */
    public static function server(
        string $local,
        int $flags,
        ?Variable $contextVar = null
    ): array {
        unset($contextVar);
        if (!\function_exists('stream_socket_server')) {
            return [false, 0, 'stream_socket_server unavailable', null];
        }
        if (\str_contains($local, "\0")) {
            return [false, 0, 'Unable to parse local socket path', null];
        }

        $parsed = self::parseRemoteSocket($local);
        if (null === $parsed) {
            return [false, 0, 'Unable to parse local socket path', null];
        }

        if ('ssl' === $parsed['transport'] || 'tls' === $parsed['transport']) {
            return [false, 0, 'ssl:// transport is not supported in this compiler build', null];
        }

        if ('unix' === $parsed['transport']) {
            return [false, 0, 'unix:// transport is not supported in this compiler build', null];
        }

        if (0 === $flags) {
            $flags = VmStreamSocketNative::STREAM_SERVER_BIND | VmStreamSocketNative::STREAM_SERVER_LISTEN;
        }

        $errno = 0;
        $errstr = '';
        $beforeSockets = VmSockets::enumerateSocketFds();
        $sock = @\stream_socket_server($local, $errno, $errstr, $flags);
        if (false === $sock) {
            return [false, $errno, '' !== $errstr ? $errstr : 'Unable to create socket', null];
        }

        $socketFd = self::discoverNewSocketFd($beforeSockets);

        $handle = VmFs::adoptStreamResource($sock, $local, $socketFd);
        if (false === $handle) {
            @\fclose($sock);

            return [false, 0, 'Unable to create stream from socket', null];
        }

        return [$handle, 0, '', $socketFd];
    }

    /**
     * @param array<int, string> $beforeSockets
     */
    private static function discoverNewSocketFd(array $beforeSockets): ?int
    {
        $after = VmSockets::enumerateSocketFds();
        foreach ($after as $fd => $target) {
            if (!isset($beforeSockets[$fd])) {
                return $fd;
            }
        }

        return null;
    }

    /**
     * @return array{transport: string, host: string, port: int}|null
     */
    private static function parseRemoteSocket(string $remote): ?array
    {
        $remote = \trim($remote);
        if ('' === $remote) {
            return null;
        }

        $transport = 'tcp';
        $rest = $remote;

        if (\preg_match('#^([a-z][a-z0-9+.-]*)://(.+)$#i', $remote, $schemeMatch)) {
            $transport = \strtolower($schemeMatch[1]);
            $rest = $schemeMatch[2];
        }

        if (\preg_match('#^\[([^\]]+)\](?::(\d+))?$#', $rest, $ipv6Match)) {
            $host = $ipv6Match[1];
            $port = isset($ipv6Match[2]) ? (int) $ipv6Match[2] : 0;

            return ['transport' => $transport, 'host' => $host, 'port' => $port];
        }

        if (\preg_match('#^([^:/]+)(?::(\d+))?$#', $rest, $match)) {
            return [
                'transport' => $transport,
                'host' => $match[1],
                'port' => isset($match[2]) ? (int) $match[2] : 0,
            ];
        }

        return null;
    }

    private static function connectTimeoutFromContext(?Variable $contextVar): ?float
    {
        if (null === $contextVar) {
            return null;
        }
        $resolved = $contextVar->resolveIndirect();
        if (!VmStreamContext::isRepresentation($resolved)) {
            return null;
        }

        $options = VmStreamContext::getOptionsHashTable($resolved);
        $socket = $options->find('socket');
        if (null === $socket) {
            return null;
        }
        $socketResolved = $socket->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $socketResolved->type) {
            return null;
        }
        $timeoutVar = $socketResolved->toArray()->find('connect_timeout');
        if (null === $timeoutVar) {
            return null;
        }
        $timeoutResolved = $timeoutVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $timeoutResolved->type) {
            return (float) $timeoutResolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $timeoutResolved->type) {
            return $timeoutResolved->toFloat();
        }

        return null;
    }
}
