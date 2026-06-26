<?php

declare(strict_types=1);

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Web;

use PHPCompiler\ext\standard\SuperglobalNames;
use PHPCompiler\ext\standard\VmEnvEnvironNative;
use PHPCompiler\ext\standard\VmParseStr;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Populate CGI-style superglobals for compiled PHP scripts (VM mode).
 */
final class Superglobals
{
    private static ?Context $activeContext = null;

    public static function setActiveContext(?Context $context): void
    {
        self::$activeContext = $context;
    }

    public static function getActiveContext(): ?Context
    {
        return self::$activeContext;
    }

    /** Maximum incoming request headers mapped into $_SERVER (issue #77). */
    public const MAX_HTTP_HEADERS = 64;

    /** Maximum length of a single header name or value after trimming. */
    public const MAX_HTTP_HEADER_LENGTH = 8192;

    /** Maximum JSON decode depth for POST bodies (issue #52). */
    public const MAX_JSON_DECODE_DEPTH = 512;

    /** @var list<string> */
    public const NAMES = SuperglobalNames::ALL;

    public static function isSuperglobalName(string $name): bool
    {
        return SuperglobalNames::isSuperglobalName($name);
    }

    /** VM implementation shared with {@see compiler_is_superglobal_name} execute(). */
    public static function isSuperglobalNameVm(string $name): bool
    {
        return SuperglobalNames::isSuperglobalName($name);
    }

    /**
     * Raw request body from REQUEST_BODY / CGI stdin (issue #289, #50).
     *
     * Same bytes as populatePost() parses for application/x-www-form-urlencoded;
     * JSON and other payloads are returned unchanged for php://input.
     */
    public static function readRequestBody(): string
    {
        $fromFile = getenv('REQUEST_BODY_FILE');
        if (false !== $fromFile && '' !== $fromFile && is_readable($fromFile)) {
            $contents = file_get_contents($fromFile);

            return false === $contents ? '' : $contents;
        }
        $fromEnv = getenv('REQUEST_BODY');

        return false === $fromEnv ? '' : $fromEnv;
    }

    /**
     * Mirror CLI -q / -p / script path into CGI env for native superglobal refresh (issues #201, #642).
     */
    public static function exportCgiEnvironment(
        ?string $queryString = null,
        ?string $postBody = null,
        ?string $scriptFilename = null
    ): void {
        if (null !== $queryString) {
            putenv('QUERY_STRING='.$queryString);
            $_ENV['QUERY_STRING'] = $queryString;
            $_SERVER['QUERY_STRING'] = $queryString;
        }
        if (null !== $postBody) {
            putenv('REQUEST_BODY='.$postBody);
            $_ENV['REQUEST_BODY'] = $postBody;
            $_SERVER['REQUEST_BODY'] = $postBody;
            if ('' !== $postBody) {
                putenv('REQUEST_METHOD=POST');
                $_ENV['REQUEST_METHOD'] = 'POST';
                $_SERVER['REQUEST_METHOD'] = 'POST';
            }
        }
        if (null !== $scriptFilename && '' !== $scriptFilename) {
            putenv('SCRIPT_FILENAME='.$scriptFilename);
            $_ENV['SCRIPT_FILENAME'] = $scriptFilename;
            $_SERVER['SCRIPT_FILENAME'] = $scriptFilename;
        }
    }

    public static function populateFromEnvironment(
        Context $context,
        ?string $queryString = null,
        ?string $postBody = null,
        ?string $scriptFilename = null
    ): void {
        self::$activeContext = $context;
        self::exportCgiEnvironment($queryString, $postBody, $scriptFilename);
        if (null === $queryString) {
            $fromEnv = getenv('QUERY_STRING');
            $queryString = false === $fromEnv ? '' : $fromEnv;
        }
        self::populateGet($context, $queryString);

        if (null === $postBody) {
            $postBody = self::readRequestBody();
        }
        $method = self::requestMethod();
        if (self::shouldPopulatePost($method, $postBody)) {
            self::populatePost($context, $postBody);
        } else {
            $context->ensureSuperglobal('_POST');
        }
        $cookieHeader = getenv('HTTP_COOKIE');
        self::populateCookie(
            $context,
            false === $cookieHeader ? '' : $cookieHeader
        );
        self::populateServer($context, $queryString, $postBody);
        self::populateRequest($context);
        ResponseContext::syncHeaderQueueFromEnvironment();
        // Keep context for putenv() → $_ENV/$_SERVER sync during script execution (#1058, #1960).
    }

    /**
     * Populate CLI globals ($argc/$argv) and mirror into $_SERVER (php-src sapi/cli parity, #4139).
     *
     * @param list<string> $argv
     */
    public static function populateCliArgv(Context $context, array $argv): void
    {
        // Always define both globals (Zend: they exist even when empty).
        $argv = array_values(array_map('strval', $argv));
        $context->cliRequestArgv = $argv;
        $argc = count($argv);

        $argvHt = \PHPCompiler\ext\standard\VmCliArgv::buildArgvTable($argv);
        $argvVar = $context->ensureGlobal('argv');
        $argvVar->array($argvHt);
        $argcVar = $context->ensureGlobal('argc');
        $argcVar->int($argc);

        $server = $context->ensureSuperglobal('_SERVER')->toArray();
        $argcServer = new Variable();
        $argcServer->int($argc);
        $server->add('argc', $argcServer);

        $argvServer = new Variable(Variable::TYPE_ARRAY);
        // Zend exposes an array value here; keep it independent of $argv mutations.
        $argvServer->array($argvHt->duplicate());
        $server->add('argv', $argvServer);
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
        $parts = explode('-', $segment);
        $out = [];
        foreach ($parts as $part) {
            $out[] = self::headerSegmentTitleCase($part);
        }

        return implode('-', $out);
    }

    private static function headerSegmentTitleCase(string $part): string
    {
        return '' === $part ? '' : ucfirst($part);
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
     * CGI env entries (HTTP_* / CONTENT_*).
     *
     * @return array<string, string>
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

    /**
     * Whether to parse the raw body into $_POST (issue #291).
     *
     * POST always uses form parsing when a body is present. PUT/PATCH/DELETE only
     * populate $_POST for application/x-www-form-urlencoded; JSON and other payloads
     * stay on REQUEST_BODY / php://input.
     */
    public static function shouldPopulatePost(string $method, string $postBody): bool
    {
        if ('' === $postBody) {
            return false;
        }
        $upper = strtoupper($method);
        if ('' === $upper) {
            return true;
        }
        if ('POST' === $upper) {
            return true;
        }
        if (!in_array($upper, ['PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        return self::isFormUrlencodedContentType();
    }

    public static function requestMethod(): string
    {
        $method = getenv('REQUEST_METHOD');

        return false === $method ? '' : $method;
    }

    public static function contentTypeMediaType(): string
    {
        $contentType = getenv('CONTENT_TYPE');
        if (false === $contentType || '' === $contentType) {
            $contentType = getenv('HTTP_CONTENT_TYPE');
        }
        if (false === $contentType || '' === $contentType) {
            return '';
        }
        $contentType = strtolower(trim($contentType));
        $semi = strpos($contentType, ';');
        if (false !== $semi) {
            $contentType = substr($contentType, 0, $semi);
        }

        return trim($contentType);
    }

    public static function isFormUrlencodedContentType(): bool
    {
        return 'application/x-www-form-urlencoded' === self::contentTypeMediaType();
    }

    public static function isJsonContentType(): bool
    {
        return 'application/json' === self::contentTypeMediaType();
    }

    public static function isMultipartContentType(): bool
    {
        return str_starts_with(self::contentTypeMediaType(), 'multipart/form-data');
    }

    private static function populateGet(Context $context, string $queryString): void
    {
        $get = $context->ensureSuperglobal('_GET');
        self::populateFormEncoded($get->toArray(), $queryString);
    }

    /** JIT/AOT standalone refresh — $_GET table (#9907). */
    public static function buildGetTableForRefresh(): HashTable
    {
        $get = new HashTable();
        $queryString = getenv('QUERY_STRING');

        self::populateFormEncoded($get, false === $queryString ? '' : $queryString);

        return $get;
    }

    /** JIT/AOT standalone refresh — $_COOKIE table (#9907). */
    public static function buildCookieTableForRefresh(): HashTable
    {
        $cookie = new HashTable();
        $cookieHeader = getenv('HTTP_COOKIE');
        self::populateCookieHeader($cookie, false === $cookieHeader ? '' : $cookieHeader);

        return $cookie;
    }

    /** JIT/AOT standalone refresh — $_REQUEST table (#9907). */
    public static function buildRequestTableForRefresh(): HashTable
    {
        $request = new HashTable();
        $queryString = getenv('QUERY_STRING');
        $queryString = false === $queryString ? '' : $queryString;
        if ('' !== $queryString) {
            self::populateFormEncoded($request, $queryString);
        }
        $postBody = self::readRequestBody();
        if (self::shouldPopulatePost(self::requestMethod(), $postBody)) {
            $post = new HashTable();
            $files = new HashTable();
            self::populatePostIntoTables($post, $files, $postBody);
            $request->mergeStringKeysFrom($post, true);
        }

        return $request;
    }

    /**
     * JIT/AOT standalone refresh — $_SERVER table (#9907).
     *
     * @param bool $aotSoftware when true, SERVER_SOFTWARE is PHP-Compiler-AOT (standalone binary)
     */
    public static function buildServerTableForRefresh(bool $aotSoftware = true): HashTable
    {
        $queryString = getenv('QUERY_STRING');
        $queryString = false === $queryString ? '' : $queryString;
        $postBody = self::readRequestBody();
        $server = new HashTable();
        self::populateServerTable($server, $queryString, $postBody, $aotSoftware);
        self::applyCgiHeadersFromEnviron($server);

        return $server;
    }

    /**
     * Map HTTP_* / CONTENT_* keys from the process environ into a server table (#9907).
     */
    public static function applyCgiHeadersFromEnviron(HashTable $server): void
    {
        foreach (VmEnvEnvironNative::enumerate() as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'CONTENT_')) {
                self::setOrUpdateStringEntry($server, $key, $value);
            }
        }
    }

    private static function populateServerTable(
        HashTable $server,
        string $queryString,
        string $postBody,
        bool $aotSoftware
    ): void {
        $method = self::requestMethod();
        if ('' === $method) {
            $method = '' !== $postBody ? 'POST' : 'GET';
        }

        $scriptName = getenv('SCRIPT_NAME');
        if (false === $scriptName || '' === $scriptName) {
            $scriptName = '/index.php';
        }

        self::setOrUpdateStringEntry($server, 'REQUEST_METHOD', $method);
        self::setOrUpdateStringEntry($server, 'QUERY_STRING', $queryString);
        self::setOrUpdateStringEntry($server, 'SCRIPT_NAME', $scriptName);
        self::setOrUpdateStringEntry($server, 'PHP_SELF', $scriptName);

        $scriptFilename = self::resolveScriptFilename($scriptName);
        if ('' !== $scriptFilename) {
            self::setOrUpdateStringEntry($server, 'SCRIPT_FILENAME', $scriptFilename);
        }

        $requestUri = getenv('REQUEST_URI');
        if (false === $requestUri || '' === $requestUri) {
            $requestUri = $scriptName;
            if ('' !== $queryString) {
                $requestUri .= '?'.$queryString;
            }
        }
        self::setOrUpdateStringEntry($server, 'REQUEST_URI', $requestUri);

        $pathInfo = getenv('PATH_INFO');
        if (false === $pathInfo || '' === $pathInfo) {
            $pathInfo = self::derivePathInfo($scriptName, $requestUri);
        }
        self::setOrUpdateStringEntry($server, 'PATH_INFO', $pathInfo);

        self::setOrUpdateStringEntry($server, 'GATEWAY_INTERFACE', 'CGI/1.1');
        $serverProtocol = getenv('SERVER_PROTOCOL');
        if (false === $serverProtocol || '' === $serverProtocol) {
            $serverProtocol = 'HTTP/1.1';
        }
        self::setOrUpdateStringEntry($server, 'SERVER_PROTOCOL', $serverProtocol);
        self::setOrUpdateStringEntry(
            $server,
            'SERVER_SOFTWARE',
            $aotSoftware ? 'PHP-Compiler-AOT' : 'PHP-Compiler-VM'
        );

        $documentRoot = getenv('DOCUMENT_ROOT');
        if (false !== $documentRoot && '' !== $documentRoot) {
            self::setOrUpdateStringEntry($server, 'DOCUMENT_ROOT', $documentRoot);
        }

        $remoteAddr = getenv('REMOTE_ADDR');
        if (false !== $remoteAddr && '' !== $remoteAddr) {
            self::setOrUpdateStringEntry($server, 'REMOTE_ADDR', $remoteAddr);
        }
        $remotePort = getenv('REMOTE_PORT');
        if (false !== $remotePort && '' !== $remotePort) {
            self::setOrUpdateStringEntry($server, 'REMOTE_PORT', $remotePort);
        }

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'CONTENT_')) {
                self::setOrUpdateStringEntry($server, $key, $value);
            }
        }

        self::applySchemeAndPort($server);
    }

    private static function populatePost(Context $context, string $postBody): void
    {
        $post = $context->ensureSuperglobal('_POST');
        $files = $context->ensureSuperglobal('_FILES');
        self::populatePostIntoTables($post->toArray(), $files->toArray(), $postBody);
    }

    /** JIT/AOT standalone refresh — $_POST table (#9907). */
    public static function buildPostTableForRefresh(): HashTable
    {
        $post = new HashTable();
        $files = new HashTable();
        $postBody = self::readRequestBody();
        if (self::shouldPopulatePost(self::requestMethod(), $postBody)) {
            self::populatePostIntoTables($post, $files, $postBody);
        }

        return $post;
    }

    /** JIT/AOT standalone refresh — $_FILES table (#9907). */
    public static function buildFilesTableForRefresh(): HashTable
    {
        $post = new HashTable();
        $files = new HashTable();
        $postBody = self::readRequestBody();
        if (self::shouldPopulatePost(self::requestMethod(), $postBody)) {
            self::populatePostIntoTables($post, $files, $postBody);
        }

        return $files;
    }

    private static function populatePostIntoTables(HashTable $post, HashTable $files, string $postBody): void
    {
        if (self::isJsonContentType()) {
            self::populateJson($post, $postBody);
        } elseif (self::isMultipartContentType()) {
            MultipartParser::populate($post, $files, $postBody);
        } else {
            self::populateFormEncoded($post, $postBody);
        }
    }

    /**
     * Decode application/json POST body into $_POST (issue #52).
     */
    private static function populateJson(HashTable $ht, string $body): void
    {
        if ('' === $body) {
            return;
        }
        if (strlen($body) > DevServer::maxRequestBody()) {
            return;
        }
        try {
            $data = json_decode($body, true, self::MAX_JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return;
        }
        if (!is_array($data)) {
            return;
        }
        VmParseStr::mergeInto($ht, $data);
    }

    private static function populateServer(
        Context $context,
        string $queryString,
        string $postBody
    ): void {
        $server = $context->ensureSuperglobal('_SERVER')->toArray();
        self::populateServerTable($server, $queryString, $postBody, false);
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
                self::setOrUpdateStringEntry($server, 'HTTP_HOST', $host);
            }
        }

        $https = self::detectHttps($server);
        $scheme = $https ? 'https' : 'http';
        self::setOrUpdateStringEntry($server, 'REQUEST_SCHEME', $scheme);
        if ($https) {
            self::setOrUpdateStringEntry($server, 'HTTPS', 'on');
        } else {
            $httpsKey = new Variable(Variable::TYPE_STRING);
            $httpsKey->string('HTTPS');
            if ($server->offsetIsSet($httpsKey)) {
                $server->offsetUnset($httpsKey);
            }
        }

        [$serverName, $portFromHost] = self::parseHostAndPort($host);
        $port = self::resolveServerPort($https, $portFromHost);
        self::setOrUpdateStringEntry($server, 'SERVER_PORT', (string) $port);

        if ('' !== $serverName) {
            self::setOrUpdateStringEntry($server, 'SERVER_NAME', $serverName);
        } elseif ('' !== $host) {
            self::setOrUpdateStringEntry($server, 'SERVER_NAME', $host);
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

    /** CGI keys mirrored into $_SERVER when updated via putenv() during a VM request. */
    private const PUTENV_SERVER_KEYS = [
        'REQUEST_METHOD',
        'PATH_INFO',
        'SCRIPT_NAME',
        'SCRIPT_FILENAME',
        'QUERY_STRING',
        'REQUEST_URI',
        'SERVER_PROTOCOL',
        'DOCUMENT_ROOT',
        'REMOTE_ADDR',
        'REMOTE_PORT',
    ];

    /**
     * Keep $_ENV / $_SERVER superglobals in sync after putenv() (assignment form only).
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
        self::setOrUpdateStringEntry($env, $key, $fromEnv);
        if (!in_array($key, self::PUTENV_SERVER_KEYS, true)) {
            return;
        }
        $server = self::$activeContext->ensureSuperglobal('_SERVER')->toArray();
        self::setOrUpdateStringEntry($server, $key, $fromEnv);
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
        VmParseStr::mergeInto($ht, $params);
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
            VmParseStr::mergeInto($ht, $params);
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

    private static function setOrUpdateStringEntry(HashTable $ht, string $key, string $value): void
    {
        $v = new Variable(Variable::TYPE_STRING);
        $v->string($value);
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        if ($ht->offsetIsSet($keyVar)) {
            $ht->update($key, $v);
        } else {
            $ht->add($key, $v);
        }
    }
}
