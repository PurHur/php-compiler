<?php

declare(strict_types=1);

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Web;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Populate CGI-style superglobals for compiled PHP scripts (VM mode).
 */
final class Superglobals
{
    private static ?Context $activeContext = null;

    /** Maximum incoming request headers mapped into $_SERVER (issue #77). */
    public const MAX_HTTP_HEADERS = 64;

    /** Maximum length of a single header name or value after trimming. */
    public const MAX_HTTP_HEADER_LENGTH = 8192;

    public const NAMES = [
        '_GET',
        '_POST',
        '_SERVER',
        '_REQUEST',
        '_COOKIE',
        '_ENV',
        '_FILES',
        '_SESSION',
    ];

    public static function isSuperglobalName(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public static function populateFromEnvironment(
        Context $context,
        ?string $queryString = null,
        ?string $postBody = null,
        ?string $scriptFilename = null
    ): void {
        self::$activeContext = $context;
        if (null !== $scriptFilename && '' !== $scriptFilename) {
            putenv('SCRIPT_FILENAME='.$scriptFilename);
        }
        if (null === $queryString) {
            $fromEnv = getenv('QUERY_STRING');
            $queryString = false === $fromEnv ? '' : $fromEnv;
        }
        self::populateGet($context, $queryString);

        if (null === $postBody) {
            $fromEnv = getenv('REQUEST_BODY');
            $postBody = false === $fromEnv ? '' : $fromEnv;
        }
        self::populatePost($context, $postBody);
        $cookieHeader = getenv('HTTP_COOKIE');
        self::populateCookie(
            $context,
            false === $cookieHeader ? '' : $cookieHeader
        );
        self::populateServer($context, $queryString, $postBody);
        self::populateRequest($context);
        self::$activeContext = null;
    }

    /**
     * Parse a CGI HTTP_COOKIE / Cookie header into $_COOKIE (issue #271).
     */
    public static function populateCookie(Context $context, string $cookieHeader): void
    {
        $cookie = $context->ensureSuperglobal('_COOKIE');
        self::populateCookieHeader($cookie->toArray(), $cookieHeader);
    }

    /**
     * Map an HTTP header name to a CGI-style $_SERVER key (HTTP_* or CONTENT_*).
     */
    public static function headerNameToServerKey(string $name): string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        if ('CONTENT_TYPE' === $normalized || 'CONTENT_LENGTH' === $normalized) {
            return $normalized;
        }

        return 'HTTP_'.$normalized;
    }

    /**
     * Map a CGI $_SERVER key to an HTTP header name for getallheaders() (issue #307).
     */
    public static function serverKeyToHeaderName(string $key): ?string
    {
        if (str_starts_with($key, 'HTTP_')) {
            $segment = substr($key, 5);
        } elseif ('CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key) {
            $segment = $key;
        } else {
            return null;
        }

        $segment = strtolower(str_replace('_', '-', $segment));

        return implode('-', array_map(
            static fn (string $part): string => '' === $part ? '' : ucfirst($part),
            explode('-', $segment)
        ));
    }

    /**
     * Request headers from the active VM $_SERVER or CGI environment (issue #307).
     *
     * @return array<string, string>
     */
    public static function collectRequestHeaders(): array
    {
        $headers = [];
        $server = self::readServerEntries();
        foreach ($server as $key => $value) {
            $name = self::serverKeyToHeaderName($key);
            if (null === $name) {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function readServerEntries(): array
    {
        $entries = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'CONTENT_')) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }

    /**
     * Apply parsed request headers to PHP $_SERVER and putenv for CGI/AOT refresh.
     *
     * @param array<string, string> $headers lowercase header name => value
     *
     * @return array<string, string> CGI env entries (HTTP_* / CONTENT_*)
     */
    public static function applyHttpHeaders(array $headers): array
    {
        $cgi = [];
        $count = 0;
        foreach ($headers as $name => $value) {
            if ($count >= self::MAX_HTTP_HEADERS) {
                break;
            }
            if (!is_string($name) || !is_string($value)) {
                continue;
            }
            $name = trim($name);
            $value = trim($value);
            if ('' === $name || strlen($name) > self::MAX_HTTP_HEADER_LENGTH
                || strlen($value) > self::MAX_HTTP_HEADER_LENGTH
                || str_contains($value, "\r") || str_contains($value, "\n")) {
                continue;
            }
            $key = self::headerNameToServerKey($name);
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
            $cgi[$key] = $value;
            ++$count;
        }

        return $cgi;
    }

    private static function populateGet(Context $context, string $queryString): void
    {
        $get = $context->ensureSuperglobal('_GET');
        self::populateFormEncoded($get->toArray(), $queryString);
    }

    private static function populatePost(Context $context, string $postBody): void
    {
        $post = $context->ensureSuperglobal('_POST');
        self::populateFormEncoded($post->toArray(), $postBody);
    }

    private static function populateServer(
        Context $context,
        string $queryString,
        string $postBody
    ): void {
        $server = $context->ensureSuperglobal('_SERVER')->toArray();

        $method = getenv('REQUEST_METHOD');
        if (false === $method || '' === $method) {
            $method = '' !== $postBody ? 'POST' : 'GET';
        }

        $scriptName = getenv('SCRIPT_NAME');
        if (false === $scriptName || '' === $scriptName) {
            $scriptName = '/index.php';
        }

        self::setStringEntry($server, 'REQUEST_METHOD', $method);
        self::setStringEntry($server, 'QUERY_STRING', $queryString);
        self::setStringEntry($server, 'SCRIPT_NAME', $scriptName);
        self::setStringEntry($server, 'PHP_SELF', $scriptName);

        $scriptFilename = self::resolveScriptFilename($scriptName);
        if ('' !== $scriptFilename) {
            self::setStringEntry($server, 'SCRIPT_FILENAME', $scriptFilename);
        }

        $requestUri = getenv('REQUEST_URI');
        if (false === $requestUri || '' === $requestUri) {
            $requestUri = $scriptName;
            if ('' !== $queryString) {
                $requestUri .= '?'.$queryString;
            }
        }
        self::setStringEntry($server, 'REQUEST_URI', $requestUri);

        $pathInfo = getenv('PATH_INFO');
        if (false === $pathInfo || '' === $pathInfo) {
            $pathInfo = self::derivePathInfo($scriptName, $requestUri);
        }
        if ('' !== $pathInfo) {
            self::setStringEntry($server, 'PATH_INFO', $pathInfo);
        }

        self::setStringEntry($server, 'GATEWAY_INTERFACE', 'CGI/1.1');
        $serverProtocol = getenv('SERVER_PROTOCOL');
        if (false === $serverProtocol || '' === $serverProtocol) {
            $serverProtocol = 'HTTP/1.1';
        }
        self::setStringEntry($server, 'SERVER_PROTOCOL', $serverProtocol);
        self::setStringEntry($server, 'SERVER_SOFTWARE', 'PHP-Compiler-VM');

        $documentRoot = getenv('DOCUMENT_ROOT');
        if (false !== $documentRoot && '' !== $documentRoot) {
            self::setStringEntry($server, 'DOCUMENT_ROOT', $documentRoot);
        }

        $remoteAddr = getenv('REMOTE_ADDR');
        if (false !== $remoteAddr && '' !== $remoteAddr) {
            self::setStringEntry($server, 'REMOTE_ADDR', $remoteAddr);
        }
        $remotePort = getenv('REMOTE_PORT');
        if (false !== $remotePort && '' !== $remotePort) {
            self::setStringEntry($server, 'REMOTE_PORT', $remotePort);
        }

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'CONTENT_')) {
                self::setStringEntry($server, $key, $value);
            }
        }

        self::applySchemeAndPort($server);
    }

    /**
     * Derive REQUEST_SCHEME, HTTPS, SERVER_PORT, and SERVER_NAME (issue #235).
     */
    public static function applySchemeAndPort(HashTable $server): void
    {
        $host = self::readStringEntry($server, 'HTTP_HOST');
        if ('' === $host) {
            $fromEnv = getenv('HTTP_HOST');
            $host = false === $fromEnv ? '' : $fromEnv;
            if ('' !== $host) {
                self::setStringEntry($server, 'HTTP_HOST', $host);
            }
        }

        $https = self::detectHttps($server);
        $scheme = $https ? 'https' : 'http';
        self::setStringEntry($server, 'REQUEST_SCHEME', $scheme);
        if ($https) {
            self::setStringEntry($server, 'HTTPS', 'on');
        }

        [$serverName, $portFromHost] = self::parseHostAndPort($host);
        $port = self::resolveServerPort($https, $portFromHost);
        self::setStringEntry($server, 'SERVER_PORT', (string) $port);

        if ('' !== $serverName) {
            self::setStringEntry($server, 'SERVER_NAME', $serverName);
        } elseif ('' !== $host) {
            self::setStringEntry($server, 'SERVER_NAME', $host);
        }
    }

    /**
     * @return array{0: string, 1: ?int} server name and optional port from Host header
     */
    public static function parseHostAndPort(string $host): array
    {
        if ('' === $host) {
            return ['', null];
        }
        if ('[' === $host[0]) {
            $close = strpos($host, ']');
            if (false !== $close) {
                $name = substr($host, 1, $close - 1);
                if (isset($host[$close + 1]) && ':' === $host[$close + 1]) {
                    $port = (int) substr($host, $close + 2);

                    return [$name, $port > 0 ? $port : null];
                }

                return [$name, null];
            }
        }
        $colon = strrpos($host, ':');
        if (false !== $colon && false === strpos($host, ':', $colon + 1)) {
            $port = (int) substr($host, $colon + 1);
            if ($port > 0) {
                return [substr($host, 0, $colon), $port];
            }
        }

        return [$host, null];
    }

    public static function detectHttps(HashTable $server): bool
    {
        $https = getenv('HTTPS');
        if (false !== $https && '' !== $https && '0' !== $https && 'off' !== strtolower($https)) {
            return true;
        }

        $proto = self::readStringEntry($server, 'HTTP_X_FORWARDED_PROTO');
        if ('' === $proto) {
            $fromEnv = getenv('HTTP_X_FORWARDED_PROTO');
            $proto = false === $fromEnv ? '' : $fromEnv;
        }

        return 'https' === strtolower($proto);
    }

    private static function resolveServerPort(bool $https, ?int $portFromHost): int
    {
        $fromEnv = getenv('SERVER_PORT');
        if (false !== $fromEnv && '' !== $fromEnv && ctype_digit($fromEnv)) {
            return (int) $fromEnv;
        }
        if (null !== $portFromHost && $portFromHost > 0) {
            return $portFromHost;
        }

        return $https ? 443 : 80;
    }

    private static function readStringEntry(HashTable $ht, string $key): string
    {
        $var = $ht->find($key);
        if (null === $var) {
            return '';
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $resolved->type) {
            return '';
        }

        return $resolved->toString();
    }

    /**
     * Keep $_ENV superglobal in sync after putenv() (assignment form only).
     */
    public static function syncEnvAfterPutenv(string $assignment): void
    {
        if (null === self::$activeContext || !str_contains($assignment, '=')) {
            return;
        }
        [$key, $value] = explode('=', $assignment, 2);
        if ('' === $key) {
            return;
        }
        $fromEnv = \getenv($key);
        if (false === $fromEnv) {
            return;
        }
        $env = self::$activeContext->ensureSuperglobal('_ENV')->toArray();
        self::setStringEntry($env, $key, $fromEnv);
    }

    private static function populateRequest(Context $context): void
    {
        $request = $context->ensureSuperglobal('_REQUEST')->toArray();
        $get = $context->getSuperglobal('_GET');
        $post = $context->getSuperglobal('_POST');
        if (null !== $get) {
            $request->mergeStringKeysFrom($get->toArray());
        }
        if (null !== $post) {
            $request->mergeStringKeysFrom($post->toArray(), true);
        }
    }

    private static function populateFormEncoded(HashTable $ht, string $body): void
    {
        if ('' === $body) {
            return;
        }
        $params = [];
        parse_str($body, $params);
        self::mergeParsedParams($ht, $params);
    }

    private static function populateCookieHeader(HashTable $ht, string $header): void
    {
        if ('' === $header) {
            return;
        }
        foreach (explode(';', $header) as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                continue;
            }
            $decoded = urldecode($segment);
            $eq = strpos($decoded, '=');
            if (false === $eq) {
                continue;
            }
            $name = substr($decoded, 0, $eq);
            if ('' === $name) {
                continue;
            }
            $value = substr($decoded, $eq + 1);
            $params = [];
            parse_str($name.'='.$value, $params);
            self::mergeParsedParams($ht, $params);
        }
    }

    /**
     * Merge PHP parse_str() output into a VM hashtable (supports nested keys and lists).
     *
     * @param array<int|string, mixed> $params
     */
    private static function mergeParsedParams(HashTable $ht, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $child = self::ensureArrayChild($ht, $key);
                self::mergeParsedParams($child, $value);

                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            self::setScalarEntry($ht, $key, $value);
        }
    }

    /**
     * @param int|string $key
     */
    private static function ensureArrayChild(HashTable $ht, $key): HashTable
    {
        $existing = is_int($key) ? $ht->findIndex($key) : $ht->find((string) $key);
        if (null !== $existing) {
            $resolved = $existing->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                return $resolved->toArray();
            }
        }

        $nested = new HashTable();
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($nested);
        if (null !== $existing) {
            $existing->copyFrom($var);
        } elseif (is_int($key)) {
            $ht->addIndex($key, $var);
        } else {
            $ht->add((string) $key, $var);
        }

        return $nested;
    }

    /**
     * @param int|string $key
     * @param bool|float|int|string $value
     */
    private static function setScalarEntry(HashTable $ht, $key, $value): void
    {
        $var = new Variable();
        if (is_int($value)) {
            $var->int($value);
        } elseif (is_float($value)) {
            $var->float($value);
        } elseif (is_bool($value)) {
            $var->bool($value);
        } else {
            $var->string((string) $value);
        }
        if (is_int($key)) {
            $existing = $ht->findIndex($key);
            if (null !== $existing) {
                $existing->copyFrom($var);
            } else {
                $ht->addIndex($key, $var);
            }

            return;
        }
        $existing = $ht->find((string) $key);
        if (null !== $existing) {
            $existing->copyFrom($var);
        } else {
            $ht->add((string) $key, $var);
        }
    }

    /**
     * Resolve absolute filesystem path for the entry script (issue #302).
     */
    public static function resolveScriptFilename(?string $scriptName = null): string
    {
        $fromEnv = getenv('SCRIPT_FILENAME');
        if (false !== $fromEnv && '' !== $fromEnv) {
            return $fromEnv;
        }

        if (null === $scriptName) {
            $scriptName = getenv('SCRIPT_NAME');
            if (false === $scriptName || '' === $scriptName) {
                $scriptName = '/index.php';
            }
        }

        $documentRoot = getenv('DOCUMENT_ROOT');
        if (false !== $documentRoot && '' !== $documentRoot) {
            return rtrim($documentRoot, '/').$scriptName;
        }

        return '';
    }

    /**
     * Derive PATH_INFO from REQUEST_URI path suffix after SCRIPT_NAME (front-controller pattern).
     */
    public static function derivePathInfo(string $scriptName, string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = $requestUri;
            $q = strpos($path, '?');
            if (false !== $q) {
                $path = substr($path, 0, $q);
            }
        }
        if ($path === $scriptName || !str_starts_with($path, $scriptName)) {
            return '';
        }

        return substr($path, strlen($scriptName));
    }

    private static function setStringEntry(HashTable $ht, string $key, string $value): void
    {
        $v = new Variable(Variable::TYPE_STRING);
        $v->string($value);
        $ht->add($key, $v);
    }
}
