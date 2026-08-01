<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ssh2;

/**
 * Optional libssh2 FFI + TCP connect probe (PECL ssh2; #6385).
 *
 * Ubuntu 22.04 CI image has no libssh2 — connect uses a short TCP probe and returns false
 * with a Zend-style warning when the host/port is unreachable (no daemon required for CI).
 */
final class VmSsh2Native
{
    /** @var \FFI|null|false */
    private static $ffi = false;

    public static function hasLibssh2(): bool
    {
        return null !== self::ffi();
    }

    /**
     * Probe TCP reachability of host:port (PECL fails similarly when the daemon is down).
     */
    public static function tcpProbe(string $host, int $port, float $timeoutSec = 0.25): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @\fsockopen($host, $port, $errno, $errstr, $timeoutSec);
        if (false === $fp) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /**
     * @return \FFI|null
     */
    private static function ffi()
    {
        if (false !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffi = null;

            return null;
        }
        // Minimal probe — full handshake lands when libssh2 is on the image.
        $cdef = 'int libssh2_init(int flags); void libssh2_exit(void);';
        foreach (['libssh2.so.1', 'libssh2.so', '/usr/lib/x86_64-linux-gnu/libssh2.so.1'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffi = null;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
