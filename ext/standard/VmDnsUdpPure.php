<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * UDP DNS exchange without libc socket FFI (#8937, pairs {@see VmDnsUdpNative}).
 *
 * Bootstrap path when FFI is disabled: host stream_socket_client under Zend VM.
 *
 * php-src: ext/standard/dns.c — UDP DNS transport
 */
final class VmDnsUdpPure
{
    private const TIMEOUT_SEC = 2;

    private const BUF_SIZE = 4096;

    public static function available(): bool
    {
        return \function_exists('stream_socket_client');
    }

    public static function exchange(string $nameserver, string $query): ?string
    {
        if ('' === $query || str_contains($nameserver, "\0")) {
            return null;
        }

        $errno = 0;
        $errstr = '';
        $sock = @\stream_socket_client(
            'udp://'.$nameserver.':53',
            $errno,
            $errstr,
            self::TIMEOUT_SEC,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $sock) {
            return null;
        }

        try {
            @\stream_set_timeout($sock, self::TIMEOUT_SEC);
            $sent = @\fwrite($sock, $query);
            if (false === $sent || $sent !== \strlen($query)) {
                return null;
            }
            $response = @\stream_get_contents($sock, self::BUF_SIZE);
            if (false === $response || '' === $response) {
                return null;
            }

            return $response;
        } finally {
            @\fclose($sock);
        }
    }
}
