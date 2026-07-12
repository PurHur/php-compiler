<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_enable_crypto() — TLS on adopted host stream resources (#4610).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_enable_crypto)
 */
final class VmStreamEnableCrypto
{
    private const UNSUPPORTED_WARNING = 'stream_socket_enable_crypto(): This stream does not support SSL/crypto';

    public static function invoke(
        int $handle,
        bool $enable,
        ?int $cryptoMethod = null,
        ?int $sessionHandle = null,
        ?string $capturePeerCert = null,
        ?string $passphrase = null
    ): bool {
        if ($enable && null === $cryptoMethod) {
            throw new \ValueError(
                'stream_socket_enable_crypto(): Argument #3 ($crypto_method) must be specified when enabling encryption'
            );
        }

        $fp = VmFs::hostStreamResource($handle);
        if (!\is_resource($fp)) {
            self::emitUnsupportedWarning();

            return !$enable;
        }

        $sessionFp = null;
        if (null !== $sessionHandle) {
            $sessionFp = VmFs::hostStreamResource($sessionHandle);
            if (!\is_resource($sessionFp)) {
                self::emitUnsupportedWarning();

                return false;
            }
        }

        if (!\function_exists('stream_socket_enable_crypto')) {
            self::emitUnsupportedWarning();

            return false;
        }

        $args = [$fp, $enable];
        if (null !== $cryptoMethod) {
            $args[] = $cryptoMethod;
        }
        if (null !== $sessionFp) {
            $args[] = $sessionFp;
        }
        if (null !== $capturePeerCert) {
            $args[] = $capturePeerCert;
        }
        if (null !== $passphrase) {
            $args[] = $passphrase;
        }

        return (bool) @\call_user_func_array('stream_socket_enable_crypto', $args);
    }

    private static function emitUnsupportedWarning(): void
    {
        if (\function_exists('compiler_language_warning')) {
            compiler_language_warning(self::UNSUPPORTED_WARNING);
        }
    }
}
