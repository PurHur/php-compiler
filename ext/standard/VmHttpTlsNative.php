<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libssl TLS client for https:// HTTP wrapper fetches (php-src main/streams/xp_ssl.c, issue #9752).
 *
 * Thin FFI over OpenSSL — no host ext/openssl stream delegation.
 */
final class VmHttpTlsNative
{
    private const OPENSSL_INIT_LOAD_CRYPTO_STRINGS = 0x00000002;

    private const OPENSSL_INIT_LOAD_SSL_STRINGS = 0x00200000;

    private const SSL_CTRL_SET_TLSEXT_HOSTNAME = 55;

    private const TLSEXT_NAMETYPE_HOST_NAME = 0;

    private const SSL_VERIFY_PEER = 1;

    private const X509_CHECK_FLAG_ALWAYS_CHECK_SUBJECT = 0x1;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{ssl: \FFI\CData, ctx: \FFI\CData}|null
     */
    public static function connect(int $sock, string $serverName): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $ctx = null;
        $ssl = null;

        try {
            if (1 !== (int) $ffi->OPENSSL_init_ssl(
                self::OPENSSL_INIT_LOAD_CRYPTO_STRINGS | self::OPENSSL_INIT_LOAD_SSL_STRINGS,
                null
            )) {
                return null;
            }

            $method = $ffi->TLS_client_method();
            $ctx = $ffi->SSL_CTX_new($method);
            if (null === $ctx) {
                return null;
            }

            if (!self::configureVerifyPaths($ffi, $ctx)) {
                return null;
            }

            $ffi->SSL_CTX_set_verify($ctx, self::SSL_VERIFY_PEER, null);

            $ssl = $ffi->SSL_new($ctx);
            if (null === $ssl) {
                return null;
            }

            if (!self::setServerName($ffi, $ssl, $serverName)) {
                return null;
            }

            if (1 !== (int) $ffi->SSL_set_fd($ssl, $sock)) {
                return null;
            }

            if (1 !== (int) $ffi->SSL_connect($ssl)) {
                return null;
            }

            if (!self::verifyPeerHostname($ffi, $ssl, $serverName)) {
                return null;
            }

            return ['ssl' => $ssl, 'ctx' => $ctx];
        } catch (\Throwable) {
            if (null !== $ssl) {
                $ffi->SSL_free($ssl);
            }
            if (null !== $ctx) {
                $ffi->SSL_CTX_free($ctx);
            }

            return null;
        }
    }

    /**
     * @param array{ssl: \FFI\CData, ctx: \FFI\CData} $handle
     */
    public static function close(array $handle): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }

        $ffi->SSL_shutdown($handle['ssl']);
        $ffi->SSL_free($handle['ssl']);
        $ffi->SSL_CTX_free($handle['ctx']);
    }

    /**
     * @param array{ssl: \FFI\CData, ctx: \FFI\CData} $handle
     */
    public static function sendAll(array $handle, string $data): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $offset = 0;
        $len = \strlen($data);
        while ($offset < $len) {
            $remaining = \substr($data, $offset);
            $sent = (int) $ffi->SSL_write($handle['ssl'], $remaining, \strlen($remaining));
            if ($sent <= 0) {
                return false;
            }
            $offset += $sent;
        }

        return true;
    }

    /**
     * @param array{ssl: \FFI\CData, ctx: \FFI\CData} $handle
     */
    public static function recvAll(array $handle): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return '';
        }

        $buf = '';
        while (true) {
            $chunk = $ffi->new('unsigned char[8192]');
            $n = (int) $ffi->SSL_read($handle['ssl'], \FFI::addr($chunk[0]), 8192);
            if ($n <= 0) {
                break;
            }
            $buf .= \FFI::string($chunk, $n);
        }

        return $buf;
    }

    /**
     * @param \FFI\CData $ctx
     */
    private static function configureVerifyPaths(\FFI $ffi, $ctx): bool
    {
        foreach (self::caBundlePaths() as $path) {
            if (\is_readable($path) && 1 === (int) $ffi->SSL_CTX_load_verify_locations($ctx, $path, null)) {
                return true;
            }
        }

        return 1 === (int) $ffi->SSL_CTX_set_default_verify_paths($ctx);
    }

    /**
     * @return list<string>
     */
    private static function caBundlePaths(): array
    {
        $paths = [];
        $env = \getenv('SSL_CERT_FILE');
        if (false !== $env && '' !== $env) {
            $paths[] = $env;
        }
        $paths[] = '/etc/ssl/certs/ca-certificates.crt';
        $paths[] = '/etc/pki/tls/certs/ca-bundle.crt';
        $paths[] = '/etc/ssl/cert.pem';

        return $paths;
    }

    /**
     * @param \FFI\CData $ssl
     */
    private static function setServerName(\FFI $ffi, $ssl, string $serverName): bool
    {
        $host = $ffi->new('char['.(\strlen($serverName) + 1).']');
        \FFI::memcpy($host, $serverName, \strlen($serverName));

        return 1 === (int) $ffi->SSL_ctrl(
            $ssl,
            self::SSL_CTRL_SET_TLSEXT_HOSTNAME,
            self::TLSEXT_NAMETYPE_HOST_NAME,
            \FFI::addr($host[0])
        );
    }

    /**
     * @param \FFI\CData $ssl
     */
    private static function verifyPeerHostname(\FFI $ffi, $ssl, string $serverName): bool
    {
        $peer = $ffi->SSL_get1_peer_certificate($ssl);
        if (null === $peer) {
            return false;
        }

        try {
            $nameLen = \strlen($serverName);

            return 1 === (int) $ffi->X509_check_host(
                $peer,
                $serverName,
                $nameLen,
                self::X509_CHECK_FLAG_ALWAYS_CHECK_SUBJECT,
                null
            );
        } finally {
            $ffi->X509_free($peer);
        }
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
typedef struct ssl_st SSL;
typedef struct ssl_ctx_st SSL_CTX;
typedef struct ssl_method_st SSL_METHOD;
typedef struct x509_st X509;
typedef struct openssl_init_settings_st OPENSSL_INIT_SETTINGS;

int OPENSSL_init_ssl(uint64_t opts, const OPENSSL_INIT_SETTINGS *settings);
const SSL_METHOD *TLS_client_method(void);
SSL_CTX *SSL_CTX_new(const SSL_METHOD *meth);
void SSL_CTX_free(SSL_CTX *ctx);
void SSL_CTX_set_verify(SSL_CTX *ctx, int mode, void *callback);
int SSL_CTX_load_verify_locations(SSL_CTX *ctx, const char *CAfile, const char *CApath);
int SSL_CTX_set_default_verify_paths(SSL_CTX *ctx);
SSL *SSL_new(SSL_CTX *ctx);
void SSL_free(SSL *s);
long SSL_ctrl(SSL *ssl, int cmd, long larg, void *parg);
int SSL_set_fd(SSL *s, int fd);
int SSL_connect(SSL *s);
int SSL_shutdown(SSL *s);
int SSL_write(SSL *s, const void *buf, int num);
int SSL_read(SSL *s, void *buf, int num);
X509 *SSL_get1_peer_certificate(const SSL *ssl);
void X509_free(X509 *a);
int X509_check_host(X509 *x, const char *name, size_t namelen, unsigned int flags, char **peername);
CDEF;

        foreach (['libssl.so.3', 'libssl.so'] as $lib) {
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
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
