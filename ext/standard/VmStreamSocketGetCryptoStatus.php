<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_get_crypto_status() — OpenSSL WANT_READ/WRITE status (#21021).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_crypto_status)
 * php-src: main/streams/php_stream_transport.h — STREAM_CRYPTO_STATUS_{NONE,WANT_READ,WANT_WRITE}
 */
final class VmStreamSocketGetCryptoStatus
{
    public const STATUS_NONE = 0;

    public const STATUS_WANT_READ = 1;

    public const STATUS_WANT_WRITE = 2;

    private const UNSUPPORTED_WARNING = 'stream_socket_get_crypto_status(): This stream does not support SSL/crypto';

    /** @var array<int, true> handles where enable_crypto(true) succeeded */
    private static array $cryptoEnabled = [];

    public static function markCryptoEnabled(int $handle, bool $enabled): void
    {
        if ($enabled) {
            self::$cryptoEnabled[$handle] = true;
        } else {
            unset(self::$cryptoEnabled[$handle]);
        }
    }

    public static function invoke(int $handle): int
    {
        $fp = VmFs::hostStreamResource($handle);
        if (\is_resource($fp) && \function_exists('stream_socket_get_crypto_status')) {
            return (int) @\stream_socket_get_crypto_status($fp);
        }

        if (isset(self::$cryptoEnabled[$handle])) {
            return self::STATUS_NONE;
        }

        if (\is_resource($fp)) {
            $meta = @\stream_get_meta_data($fp);
            if (\is_array($meta) && isset($meta['crypto']) && \is_array($meta['crypto'])) {
                return self::STATUS_NONE;
            }
        }

        self::emitUnsupportedWarning();

        return self::STATUS_NONE;
    }

    private static function emitUnsupportedWarning(): void
    {
        if (\function_exists('compiler_language_warning')) {
            compiler_language_warning(self::UNSUPPORTED_WARNING);
        }
    }
}
