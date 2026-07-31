<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * php-src php_http.c — stream_socket HTTP POST with Z_CLIENT_HTTPSOCKET keep-alive (#24913).
 *
 * PHP-in-PHP: host stream_socket_client + VmFs handle; no new runtime C.
 * HTTPS through HTTP proxy uses CONNECT + stream_socket_enable_crypto (#26166).
 */
final class SoapHttpTransport
{
    /**
     * True when this transport can own the connection (attach httpsocket).
     */
    public static function canHandle(string $location, bool $useProxy): bool
    {
        if (!\preg_match('#^https?://#i', $location)) {
            return false;
        }

        return \function_exists('stream_socket_client');
    }

    /**
     * POST $bodyOut with pre-built request header block; reuse/close httpsocket per php_http.c.
     *
     * @return array{0: string, 1: string} response body, raw response headers (CRLF lines + trailing CRLF)
     *
     * @throws \SoapFault
     */
    public static function post(
        ObjectEntry $object,
        SoapClientState $state,
        string $location,
        string $requestHeaders,
        string $bodyOut,
        bool $useProxy
    ): array {
        $payload = SoapUrlPayload::fromLocation($location);
        if (null === $payload || null === $payload->host) {
            throw new \SoapFault('HTTP', 'Unable to parse URL');
        }

        $handle = self::resolveReusableHandle($object, $state, $payload, $useProxy);
        if (null === $handle) {
            $handle = self::connect($object, $state, $payload, $location, $useProxy);
        }

        $wire = $requestHeaders."\r\n".$bodyOut;
        $written = VmFs::fwrite($handle, $wire);
        if (false === $written || (int) $written !== \strlen($wire)) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Failed Sending HTTP SOAP request');
        }

        $parsed = self::readResponse($handle);
        if (null === $parsed) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Error Fetching http headers');
        }

        [$statusLine, $headerBlock, $body, $httpClose] = $parsed;
        $responseHeaders = $statusLine."\r\n".$headerBlock;
        if ('' !== $headerBlock && !\str_ends_with($headerBlock, "\r\n")) {
            $responseHeaders .= "\r\n";
        }

        // Digest 401 may need retry — leave socket open when keep-alive allows.
        if ($httpClose || !$state->keepAlive) {
            self::closeSocket($object, $state);
        } else {
            self::attachSocket($object, $state, $handle, $useProxy);
        }

        return [$body, $responseHeaders];
    }

    /** Drop keep-alive socket (host change / eof / Connection: close). */
    public static function closeSocket(ObjectEntry $object, SoapClientState $state): void
    {
        $handle = $state->httpSocketHandle;
        if (null !== $handle && VmFs::isValidHandle($handle)) {
            VmFs::fclose($handle);
        }
        $state->httpSocketHandle = null;
        if ($object->hasProperty('httpsocket')) {
            $object->getProperty('httpsocket')->null();
        }
        if ($object->hasProperty('_use_proxy')) {
            $object->getProperty('_use_proxy')->null();
        }
    }

    private static function attachSocket(
        ObjectEntry $object,
        SoapClientState $state,
        int $handle,
        bool $useProxy
    ): void {
        $state->httpSocketHandle = $handle;
        $ctx = $state->vmContext;
        if ($object->hasProperty('httpsocket') && null !== $ctx) {
            $object->getProperty('httpsocket')->streamHandle($handle, $ctx);
        }
        if ($object->hasProperty('_use_proxy')) {
            $object->getProperty('_use_proxy')->int($useProxy ? 1 : 0);
        }
    }

    private static function resolveReusableHandle(
        ObjectEntry $object,
        SoapClientState $state,
        SoapUrlPayload $payload,
        bool $useProxy
    ): ?int {
        $handle = $state->httpSocketHandle;
        if (null === $handle && $object->hasProperty('httpsocket')) {
            $slot = $object->getProperty('httpsocket');
            if (Variable::TYPE_NULL !== $slot->type) {
                $handle = ResourceSupport::resolveHandle($slot);
            }
        }
        if (null === $handle || !VmFs::isValidHandle($handle)) {
            $state->httpSocketHandle = null;

            return null;
        }
        if (VmFs::feof($handle)) {
            self::closeSocket($object, $state);

            return null;
        }

        // Same-host keep-alive predicate (php-src php_http.c orig vs phpurl).
        if ($object->hasProperty('httpurl') && SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            $urlVar = $object->getProperty('httpurl');
            if (Variable::TYPE_OBJECT === $urlVar->type) {
                $prev = VmSoapOpaque::urlPayload($urlVar->toObject());
                if (null !== $prev && !$prev->matchesHost($payload)) {
                    self::closeSocket($object, $state);

                    return null;
                }
            }
        }

        // Proxy mode change forces reconnect.
        if ($object->hasProperty('_use_proxy')) {
            $up = $object->getProperty('_use_proxy');
            if (Variable::TYPE_INTEGER === $up->type && ((int) $up->toInt() !== 0) !== $useProxy) {
                self::closeSocket($object, $state);

                return null;
            }
        }

        $state->httpSocketHandle = $handle;

        return $handle;
    }

    /**
     * @throws \SoapFault
     */
    private static function connect(
        ObjectEntry $object,
        SoapClientState $state,
        SoapUrlPayload $payload,
        string $location,
        bool $useProxy
    ): int {
        self::closeSocket($object, $state);

        $timeout = null !== $state->connectionTimeout ? (float) $state->connectionTimeout : 30.0;
        $useSsl = 'https' === $payload->scheme;

        if ($useProxy && null !== $state->proxyHost && null !== $state->proxyPort) {
            $remote = 'tcp://'.$state->proxyHost.':'.$state->proxyPort;
        } elseif ($useSsl) {
            $remote = 'ssl://'.$payload->host.':'.$payload->port;
        } else {
            $remote = 'tcp://'.$payload->host.':'.$payload->port;
        }

        $errno = 0;
        $errstr = '';
        $ctx = null;
        if (null !== $state->streamContextOptions) {
            $ctxOpts = [];
            if (isset($state->streamContextOptions['ssl']) && \is_array($state->streamContextOptions['ssl'])) {
                $ctxOpts['ssl'] = $state->streamContextOptions['ssl'];
            }
            if (isset($state->streamContextOptions['socket']) && \is_array($state->streamContextOptions['socket'])) {
                $ctxOpts['socket'] = $state->streamContextOptions['socket'];
            }
            if ([] !== $ctxOpts) {
                $ctx = \stream_context_create($ctxOpts);
            }
        }

        if (null !== $ctx) {
            $fp = @\stream_socket_client($remote, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT, $ctx);
        } else {
            $fp = @\stream_socket_client($remote, $errno, $errstr, $timeout, \STREAM_CLIENT_CONNECT);
        }
        if (false === $fp) {
            throw new \SoapFault('HTTP', 'Could not connect to host');
        }

        $handle = VmFs::adoptStreamResource($fp, $location);
        if (false === $handle) {
            @\fclose($fp);

            throw new \SoapFault('HTTP', 'Could not connect to host');
        }

        // php-src http_connect: proxy + SSL → CONNECT then crypto_enable (#26166).
        if ($useProxy && $useSsl) {
            self::proxyHttpsConnect($handle, $object, $state, $payload);
        }

        self::attachSocket($object, $state, $handle, $useProxy);

        return $handle;
    }

    /**
     * php-src php_http.c http_connect — CONNECT host:port + enable SSL (#26166).
     *
     * @throws \SoapFault
     */
    private static function proxyHttpsConnect(
        int $handle,
        ObjectEntry $object,
        SoapClientState $state,
        SoapUrlPayload $payload
    ): void {
        $host = (string) $payload->host;
        $port = (int) $payload->port;
        $connect = 'CONNECT '.$host.':'.$port." HTTP/1.1\r\n".
            'Host: '.$host;
        // php-src appends :port on Host when port != 80 (including 443).
        if (80 !== $port) {
            $connect .= ':'.$port;
        }
        $connect .= "\r\n";
        if (null !== $state->proxyLogin) {
            $user = $state->proxyLogin;
            $pass = null !== $state->proxyPassword ? $state->proxyPassword : '';
            $connect .= 'Proxy-Authorization: Basic '.\base64_encode($user.':'.$pass)."\r\n";
        }
        $connect .= "\r\n";

        $written = VmFs::fwrite($handle, $connect);
        if (false === $written || (int) $written !== \strlen($connect)) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Failed Sending HTTP SOAP request');
        }

        $statusLine = VmFs::fgets($handle);
        if (false === $statusLine || '' === $statusLine) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Error Fetching http headers');
        }
        // Drain header block (php-src get_http_headers).
        while (true) {
            $line = VmFs::fgets($handle);
            if (false === $line) {
                self::closeSocket($object, $state);

                throw new \SoapFault('HTTP', 'Error Fetching http headers');
            }
            if ('' === \rtrim($line, "\r\n")) {
                break;
            }
        }
        if (!\preg_match('#^HTTP/\d+\.\d+\s+200\b#i', \trim($statusLine))) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Could not connect to host');
        }

        $fp = VmFs::hostStreamResource($handle);
        if (\is_resource($fp) && null !== $payload->host) {
            // php-src: peer_name defaults to destination host, not the proxy.
            $existing = @\stream_context_get_options($fp);
            $hasPeer = \is_array($existing)
                && isset($existing['ssl'])
                && \is_array($existing['ssl'])
                && \array_key_exists('peer_name', $existing['ssl']);
            if (!$hasPeer) {
                @\stream_context_set_option($fp, 'ssl', 'peer_name', $payload->host);
            }
        }

        $crypto = self::sslMethodToCryptoMethod($state->sslMethod);
        if (null === $crypto || !VmFs::streamSocketEnableCrypto($handle, true, $crypto)) {
            self::closeSocket($object, $state);

            throw new \SoapFault('HTTP', 'Could not connect to host');
        }
    }

    /** php-src ssl_method → STREAM_CRYPTO_METHOD_*_CLIENT (#20366 / #26166). */
    private static function sslMethodToCryptoMethod(?int $sslMethod): ?int
    {
        if (null === $sslMethod) {
            return \defined('STREAM_CRYPTO_METHOD_SSLv23_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv23_CLIENT')
                : (\defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                    ? (int) \constant('STREAM_CRYPTO_METHOD_TLS_CLIENT') : null);
        }

        return match ($sslMethod) {
            SoapConstants::SOAP_SSL_METHOD_TLS => \defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_TLS_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv2 => \defined('STREAM_CRYPTO_METHOD_SSLv2_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv2_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv3 => \defined('STREAM_CRYPTO_METHOD_SSLv3_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv3_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv23 => \defined('STREAM_CRYPTO_METHOD_SSLv23_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv23_CLIENT') : null,
            default => \defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_TLS_CLIENT') : null,
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: bool}|null status line, header block, body, http_close
     */
    private static function readResponse(int $handle): ?array
    {
        $statusLine = VmFs::fgets($handle);
        if (false === $statusLine || '' === $statusLine) {
            return null;
        }
        $statusLine = \rtrim($statusLine, "\r\n");

        $headerLines = [];
        while (true) {
            $line = VmFs::fgets($handle);
            if (false === $line) {
                return null;
            }
            $trimmed = \rtrim($line, "\r\n");
            if ('' === $trimmed) {
                break;
            }
            $headerLines[] = $trimmed;
        }
        $headerBlock = [] === $headerLines ? '' : \implode("\r\n", $headerLines)."\r\n";

        $http11 = (bool) \preg_match('#^HTTP/1\.1\b#i', $statusLine);
        $connection = self::headerValue($headerBlock, 'Connection');
        $httpClose = true;
        if ($http11) {
            $httpClose = null !== $connection && 0 === \strcasecmp($connection, 'close');
        } else {
            $httpClose = null === $connection || 0 !== \strcasecmp($connection, 'Keep-Alive');
        }

        $transferEncoding = self::headerValue($headerBlock, 'Transfer-Encoding');
        $contentLength = self::headerValue($headerBlock, 'Content-Length');

        if (null !== $transferEncoding && false !== \stripos($transferEncoding, 'chunked')) {
            $body = self::readChunkedBody($handle);
            if (null === $body) {
                return null;
            }
        } elseif (null !== $contentLength && \ctype_digit($contentLength)) {
            $len = (int) $contentLength;
            $body = '';
            while (\strlen($body) < $len) {
                $chunk = VmFs::fread($handle, $len - \strlen($body));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }
            if (\strlen($body) !== $len) {
                return null;
            }
        } else {
            // Connection-close framing (HTTP/1.0 style).
            $body = '';
            while (!VmFs::feof($handle)) {
                $chunk = VmFs::fread($handle, 8192);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
            }
            $httpClose = true;
        }

        return [$statusLine, $headerBlock, $body, $httpClose];
    }

    private static function readChunkedBody(int $handle): ?string
    {
        $body = '';
        while (true) {
            $sizeLine = VmFs::fgets($handle);
            if (false === $sizeLine) {
                return null;
            }
            $sizeLine = \trim($sizeLine);
            if ('' === $sizeLine) {
                continue;
            }
            if (!\ctype_xdigit($sizeLine) && !\preg_match('/^[0-9a-fA-F]+/', $sizeLine, $m)) {
                return null;
            }
            $hex = \preg_match('/^[0-9a-fA-F]+/', $sizeLine, $m) ? $m[0] : $sizeLine;
            $size = \hexdec($hex);
            if (0 === $size) {
                // Trailer headers until blank line.
                while (true) {
                    $t = VmFs::fgets($handle);
                    if (false === $t || '' === \rtrim($t, "\r\n")) {
                        break;
                    }
                }

                return $body;
            }
            $got = '';
            while (\strlen($got) < $size) {
                $chunk = VmFs::fread($handle, $size - \strlen($got));
                if (false === $chunk || '' === $chunk) {
                    return null;
                }
                $got .= $chunk;
            }
            $body .= $got;
            // Consume trailing CRLF after chunk.
            VmFs::fgets($handle);
        }
    }

    private static function headerValue(string $headerBlock, string $name): ?string
    {
        $pattern = '/(?:^|\r\n)'.\preg_quote($name, '/').':\s*([^\r\n]*)/i';
        if (!\preg_match($pattern, $headerBlock, $m)) {
            return null;
        }

        return \trim($m[1]);
    }
}
