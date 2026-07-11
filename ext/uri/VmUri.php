<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * RFC 3986 / WHATWG URL parsing helpers — php-src ext/uri/ (#9051).
 *
 * MVP uses parse_url() for absolute http(s) and scheme URIs; expand toward full
 * php-src uri_*.c parity in follow-up issues.
 */
final class VmUri
{
    public const CLASS_URI_EXCEPTION = 'uri\\uriexception';
    public const CLASS_URI_ERROR = 'uri\\urierror';
    public const CLASS_INVALID_URI_EXCEPTION = 'uri\\invaliduriexception';
    public const CLASS_RFC3986_URI = 'uri\\rfc3986\\uri';
    public const CLASS_WHATWG_URL = 'uri\\whatwg\\url';
    public const CLASS_WHATWG_INVALID_URL = 'uri\\whatwg\\invalidurlexception';

    /** @var array<int, array<string, mixed>> */
    private static array $rfc3986State = [];

    /** @var array<int, array<string, mixed>> */
    private static array $whatWgState = [];

    /**
     * @return array{scheme: ?string, userinfo: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}|null
     */
    public static function tryParseRfc3986Parts(string $uri): ?array
    {
        $uri = trim($uri);
        if ('' === $uri) {
            return null;
        }

        $parts = parse_url($uri);
        if (false === $parts) {
            return null;
        }

        if (!isset($parts['scheme']) || '' === $parts['scheme']) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ('' === $path && isset($parts['host'])) {
            $path = '/';
        }

        return [
            'scheme' => isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null,
            'userinfo' => isset($parts['user']) ? (string) $parts['user'] : null,
            'host' => isset($parts['host']) ? (string) $parts['host'] : null,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => (string) $path,
            'query' => isset($parts['query']) ? (string) $parts['query'] : null,
            'fragment' => isset($parts['fragment']) ? (string) $parts['fragment'] : null,
        ];
    }

    /**
     * @return array{scheme: ?string, userinfo: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    public static function parseRfc3986(string $uri): array
    {
        $parsed = self::tryParseRfc3986Parts($uri);
        if (null === $parsed) {
            throw new \LogicException('Invalid RFC 3986 URI');
        }

        return $parsed;
    }

    public static function tryParseRfc3986(Context $ctx, string $uri): ?Variable
    {
        $parsed = self::tryParseRfc3986Parts($uri);
        if (null === $parsed) {
            return null;
        }

        return self::newRfc3986UriVariable($ctx, $parsed);
    }

    public static function newRfc3986UriVariable(Context $ctx, array $state): Variable
    {
        $class = self::requireClass($ctx, self::CLASS_RFC3986_URI, 'Uri\\Rfc3986\\Uri');
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$rfc3986State[$entry->id] = $state;
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function rfc3986State(ObjectEntry $object): array
    {
        return self::$rfc3986State[$object->id] ?? throw new \LogicException('Uri state missing');
    }

    public static function tryParseWhatWg(Context $ctx, string $uri): ?Variable
    {
        $state = self::tryParseRfc3986Parts($uri);
        if (null === $state) {
            return null;
        }
        $scheme = $state['scheme'] ?? '';
        if (!\in_array($scheme, ['http', 'https', 'file', 'ws', 'wss'], true)) {
            return null;
        }

        return self::newWhatWgUrlVariable($ctx, $state);
    }

    public static function newWhatWgUrlVariable(Context $ctx, array $state): Variable
    {
        $class = self::requireClass($ctx, self::CLASS_WHATWG_URL, 'Uri\\WhatWg\\Url');
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$whatWgState[$entry->id] = $state;
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function whatWgState(ObjectEntry $object): array
    {
        return self::$whatWgState[$object->id] ?? throw new \LogicException('Url state missing');
    }

    private static function requireClass(Context $ctx, string $lc, string $label): ClassEntry
    {
        $class = $ctx->classes[$lc] ?? null;
        if (!$class instanceof ClassEntry) {
            throw new \LogicException($label.' is not registered in this compiler build');
        }

        return $class;
    }
}
