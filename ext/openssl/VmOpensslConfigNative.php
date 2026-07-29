<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSL config introspection via libcrypto FFI (php-src ext/openssl/openssl.c — #6560).
 */
final class VmOpensslConfigNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array<string, string>|null
     */
    public static function certLocations(): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $iniCafile = '';
        $iniCapath = '';
        if (\function_exists('ini_get')) {
            $cafile = @\ini_get('openssl.cafile');
            if (\is_string($cafile)) {
                $iniCafile = $cafile;
            }
            $capath = @\ini_get('openssl.capath');
            if (\is_string($capath)) {
                $iniCapath = $capath;
            }
        }

        return [
            'default_cert_file' => self::ffiString($ffi->X509_get_default_cert_file()),
            'default_cert_file_env' => 'SSL_CERT_FILE',
            'default_cert_dir' => self::ffiString($ffi->X509_get_default_cert_dir()),
            'default_cert_dir_env' => 'SSL_CERT_DIR',
            'default_private_dir' => self::ffiString($ffi->X509_get_default_private_dir()),
            'default_default_cert_area' => self::ffiString($ffi->X509_get_default_cert_area()),
            'ini_cafile' => $iniCafile,
            'ini_capath' => $iniCapath,
        ];
    }

    /**
     * @return list<string>|null
     */
    public static function curveNames(): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $count = (int) $ffi->EC_get_builtin_curves(null, 0);
        if ($count <= 0) {
            return [];
        }

        $curves = $ffi->new("EC_builtin_curve[$count]");
        $loaded = (int) $ffi->EC_get_builtin_curves($curves, $count);
        $names = [];
        for ($i = 0; $i < $loaded; ++$i) {
            $nid = (int) $curves[$i]->nid;
            $name = self::ffiString($ffi->OBJ_nid2sn($nid));
            if ('' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Linked libcrypto OPENSSL_VERSION text (OpenSSL_version(OPENSSL_VERSION); #24070).
     *
     * Falls back to host OPENSSL_VERSION_TEXT when FFI is unavailable.
     */
    public static function libraryVersionText(): ?string
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $text = self::ffiString($ffi->OpenSSL_version(0));
            if ('' !== $text) {
                return $text;
            }
        }
        if (\defined('OPENSSL_VERSION_TEXT')) {
            $host = \constant('OPENSSL_VERSION_TEXT');
            if (\is_string($host) && '' !== $host) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Linked libcrypto OPENSSL_VERSION_NUMBER (OpenSSL_version_num(); #24070).
     *
     * Falls back to host OPENSSL_VERSION_NUMBER when FFI is unavailable.
     */
    public static function libraryVersionNumber(): ?int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->OpenSSL_version_num();
        }
        if (\defined('OPENSSL_VERSION_NUMBER')) {
            $host = \constant('OPENSSL_VERSION_NUMBER');
            if (\is_int($host)) {
                return $host;
            }
            if (\is_float($host)) {
                return (int) $host;
            }
        }

        return null;
    }

    /** @param \FFI\CData|string|null $ptr */
    private static function ffiString($ptr): string
    {
        if (null === $ptr) {
            return '';
        }
        if (\is_string($ptr)) {
            return $ptr;
        }

        return \FFI::string($ptr);
    }

    /** @return \FFI|null */
    private static function ffi()
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
const char *OpenSSL_version(int type);
unsigned long OpenSSL_version_num(void);

const char *X509_get_default_cert_file(void);
const char *X509_get_default_cert_dir(void);
const char *X509_get_default_private_dir(void);
const char *X509_get_default_cert_area(void);

typedef struct {
    int nid;
    const char *comment;
} EC_builtin_curve;

size_t EC_get_builtin_curves(EC_builtin_curve *r, size_t nitems);
const char *OBJ_nid2sn(int n);
CDEF;

        foreach (['libcrypto.so.3', 'libcrypto.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

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
