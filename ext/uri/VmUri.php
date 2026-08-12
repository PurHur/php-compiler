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
    public const CLASS_WHATWG_URL_VALIDATION_ERROR = 'uri\\whatwg\\urlvalidationerror';
    public const CLASS_WHATWG_URL_VALIDATION_ERROR_TYPE = 'uri\\whatwg\\urlvalidationerrortype';
    public const CLASS_RFC3986_URI_BUILDER = 'uri\\rfc3986\\uribuilder';

    /** WHATWG special schemes (url.spec.whatwg.org/#is-special). */
    public const SPECIAL_SCHEMES = ['ftp', 'file', 'http', 'https', 'ws', 'wss'];

    /** @var array<int, array<string, mixed>> */
    private static array $rfc3986State = [];

    /** @var array<int, array<string, mixed>> */
    private static array $whatWgState = [];

    /** @var array<int, array<string, mixed>> */
    private static array $builderState = [];

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

        $rawHost = isset($parts['host']) ? (string) $parts['host'] : null;

        return [
            'scheme' => isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null,
            'username' => isset($parts['user']) ? (string) $parts['user'] : null,
            'password' => isset($parts['pass']) ? (string) $parts['pass'] : null,
            'userinfo' => self::composeUserinfo(
                isset($parts['user']) ? (string) $parts['user'] : null,
                isset($parts['pass']) ? (string) $parts['pass'] : null
            ),
            'host' => self::normalizeAsciiHost($rawHost),
            'rawHost' => $rawHost,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => (string) $path,
            'query' => isset($parts['query']) ? (string) $parts['query'] : null,
            'fragment' => isset($parts['fragment']) ? (string) $parts['fragment'] : null,
        ];
    }

    /**
     * WHATWG URL / RFC 3986 ASCII registered-name lowercasing (php-src ext/uri; #28197).
     *
     * IPv6 literals and non-ASCII IDNA hosts are left unchanged.
     */
    public static function normalizeAsciiHost(?string $host): ?string
    {
        if (null === $host || '' === $host) {
            return $host;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return $host;
        }

        if (1 !== preg_match('/^[\x20-\x7E]*$/', $host)) {
            return $host;
        }

        return strtolower($host);
    }

    private static function applyHostOverride(array $state, ?string $rawHost): array
    {
        $state['host'] = self::normalizeAsciiHost($rawHost);
        $state['rawHost'] = $rawHost;

        return $state;
    }

    public static function composeUserinfo(?string $username, ?string $password): ?string
    {
        if (null === $username) {
            return null;
        }
        if (null === $password) {
            return $username;
        }

        return $username.':'.$password;
    }

    /**
     * Compose an absolute URL string from parsed components (#20541).
     *
     * @param array<string, mixed> $state
     */
    public static function composeUrlString(array $state, bool $includeFragment = true): string
    {
        $scheme = (string) ($state['scheme'] ?? '');
        $host = (string) ($state['host'] ?? '');
        $path = (string) ($state['path'] ?? '');
        $out = '' !== $scheme ? $scheme.':' : '';
        if ('' !== $host) {
            $out .= '//';
            $userinfo = $state['userinfo'] ?? self::composeUserinfo(
                isset($state['username']) && \is_string($state['username']) ? $state['username'] : null,
                isset($state['password']) && \is_string($state['password']) ? $state['password'] : null
            );
            if (\is_string($userinfo) && '' !== $userinfo) {
                $out .= $userinfo.'@';
            }
            $out .= $host;
            if (isset($state['port']) && \is_int($state['port'])) {
                $out .= ':'.$state['port'];
            }
        }
        $out .= $path;
        if (isset($state['query']) && \is_string($state['query']) && '' !== $state['query']) {
            $out .= '?'.$state['query'];
        }
        if ($includeFragment && isset($state['fragment']) && \is_string($state['fragment']) && '' !== $state['fragment']) {
            $out .= '#'.$state['fragment'];
        }

        return $out;
    }

    /** Clone WhatWg state with overrides and allocate a new Url object. */
    public static function whatWgWith(Context $ctx, ObjectEntry $object, array $overrides): Variable
    {
        $state = self::whatWgState($object);
        foreach ($overrides as $key => $value) {
            $state[$key] = $value;
        }
        if (\array_key_exists('username', $overrides) || \array_key_exists('password', $overrides)) {
            $state['userinfo'] = self::composeUserinfo(
                isset($state['username']) && \is_string($state['username']) ? $state['username'] : null,
                isset($state['password']) && \is_string($state['password']) ? $state['password'] : null
            );
        }
        if (\array_key_exists('host', $overrides)) {
            $rawHost = $overrides['host'];
            $state = self::applyHostOverride($state, \is_string($rawHost) ? $rawHost : null);
        }

        return self::newWhatWgUrlVariable($ctx, $state);
    }

    /**
     * Clone RFC 3986 Uri state with overrides and allocate a new Uri object (#20614).
     *
     * @param array<string, mixed> $overrides
     */
    public static function rfc3986With(Context $ctx, ObjectEntry $object, array $overrides): Variable
    {
        $state = self::rfc3986State($object);
        foreach ($overrides as $key => $value) {
            $state[$key] = $value;
        }
        if (\array_key_exists('userinfo', $overrides)) {
            $ui = $overrides['userinfo'];
            if (null === $ui || '' === $ui) {
                $state['username'] = null;
                $state['password'] = null;
                $state['userinfo'] = null;
            } elseif (\is_string($ui)) {
                $colon = strpos($ui, ':');
                if (false === $colon) {
                    $state['username'] = $ui;
                    $state['password'] = null;
                } else {
                    $state['username'] = substr($ui, 0, $colon);
                    $state['password'] = substr($ui, $colon + 1);
                }
                $state['userinfo'] = self::composeUserinfo($state['username'], $state['password']);
            }
        } elseif (\array_key_exists('username', $overrides) || \array_key_exists('password', $overrides)) {
            $state['userinfo'] = self::composeUserinfo(
                isset($state['username']) && \is_string($state['username']) ? $state['username'] : null,
                isset($state['password']) && \is_string($state['password']) ? $state['password'] : null
            );
        }
        if (\array_key_exists('scheme', $overrides) && \is_string($state['scheme'])) {
            $state['scheme'] = strtolower($state['scheme']);
        }
        if (\array_key_exists('host', $overrides)) {
            $rawHost = $overrides['host'];
            $state = self::applyHostOverride($state, \is_string($rawHost) ? $rawHost : null);
        }

        return self::newRfc3986UriVariable($ctx, $state);
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

    public static function hasRfc3986State(ObjectEntry $object): bool
    {
        return isset(self::$rfc3986State[$object->id]);
    }

    /**
     * Bind parsed components onto an existing Uri instance (__construct / __unserialize; #21468).
     *
     * @param array<string, mixed> $state
     */
    public static function bindRfc3986State(ObjectEntry $object, array $state): void
    {
        self::$rfc3986State[$object->id] = $state;
        $object->constructed = true;
    }

    public static function rfc3986State(ObjectEntry $object): array
    {
        return self::$rfc3986State[$object->id] ?? throw new \LogicException('Uri state missing');
    }

    /**
     * Debug/var_dump property bag (php-src uri_get_debug_properties; #21468).
     *
     * @param array<string, mixed> $state
     *
     * @return array{scheme: ?string, username: ?string, password: ?string, host: ?string, port: ?int, path: string, query: ?string, fragment: ?string}
     */
    public static function debugInfoFromState(array $state): array
    {
        return [
            'scheme' => isset($state['scheme']) && \is_string($state['scheme']) ? $state['scheme'] : null,
            'username' => isset($state['username']) && \is_string($state['username']) ? $state['username'] : null,
            'password' => isset($state['password']) && \is_string($state['password']) ? $state['password'] : null,
            'host' => isset($state['host']) && \is_string($state['host']) ? $state['host'] : null,
            'port' => isset($state['port']) && \is_int($state['port']) ? $state['port'] : null,
            'path' => (string) ($state['path'] ?? ''),
            'query' => isset($state['query']) && \is_string($state['query']) ? $state['query'] : null,
            'fragment' => isset($state['fragment']) && \is_string($state['fragment']) ? $state['fragment'] : null,
        ];
    }

    public static function tryParseWhatWg(Context $ctx, string $uri): ?Variable
    {
        $state = self::tryParseRfc3986Parts($uri);
        if (null === $state) {
            return null;
        }
        $scheme = $state['scheme'] ?? '';
        if (!\in_array($scheme, self::SPECIAL_SCHEMES, true)) {
            return null;
        }

        return self::newWhatWgUrlVariable($ctx, $state);
    }

    public static function isSpecialScheme(?string $scheme): bool
    {
        return null !== $scheme && \in_array(strtolower($scheme), self::SPECIAL_SCHEMES, true);
    }

    /**
     * Resolve $ref against a WhatWg base URL state (MVP relative resolution; #20949).
     *
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>|null
     */
    public static function resolveWhatWgParts(array $base, string $ref): ?array
    {
        $ref = trim($ref);
        if ('' === $ref) {
            return $base;
        }

        $absolute = self::tryParseRfc3986Parts($ref);
        if (null !== $absolute) {
            $scheme = $absolute['scheme'] ?? '';
            if (\in_array($scheme, self::SPECIAL_SCHEMES, true)) {
                return $absolute;
            }
        }

        if (str_starts_with($ref, '//')) {
            $scheme = (string) ($base['scheme'] ?? 'https');
            $joined = self::tryParseRfc3986Parts($scheme.':'.$ref);
            if (null === $joined) {
                return null;
            }

            return $joined;
        }

        $state = $base;
        $pathPart = $ref;
        $query = null;
        $fragment = null;
        $hashPos = strpos($pathPart, '#');
        if (false !== $hashPos) {
            $fragment = substr($pathPart, $hashPos + 1);
            $pathPart = substr($pathPart, 0, $hashPos);
        }
        $queryPos = strpos($pathPart, '?');
        if (false !== $queryPos) {
            $query = substr($pathPart, $queryPos + 1);
            $pathPart = substr($pathPart, 0, $queryPos);
        }

        if ('' === $pathPart) {
            if (null !== $query) {
                $state['query'] = $query;
            }
            $state['fragment'] = $fragment;

            return $state;
        }

        if (str_starts_with($pathPart, '/')) {
            $state['path'] = self::removeDotSegments($pathPart);
        } else {
            $basePath = (string) ($base['path'] ?? '/');
            $slash = strrpos($basePath, '/');
            $dir = false === $slash ? '/' : substr($basePath, 0, $slash + 1);
            $state['path'] = self::removeDotSegments($dir.$pathPart);
        }
        $state['query'] = $query;
        $state['fragment'] = $fragment;

        return $state;
    }

    public static function removeDotSegments(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = explode('/', $path);
        $output = [];
        foreach ($segments as $i => $segment) {
            if ('.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if (\count($output) > ($isAbsolute ? 1 : 0)) {
                    array_pop($output);
                }
                continue;
            }
            // Keep empty leading segment for absolute paths; drop other empties from // 
            if ('' === $segment) {
                if (0 === $i && $isAbsolute) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }
        if ($isAbsolute && ([] === $output || '' !== ($output[0] ?? null))) {
            array_unshift($output, '');
        }
        $joined = implode('/', $output);
        if ($isAbsolute && '' === $joined) {
            return '/';
        }

        return '' === $joined ? ($isAbsolute ? '/' : '') : $joined;
    }

    public static function whatWgResolve(Context $ctx, ObjectEntry $base, string $ref): Variable
    {
        $resolved = self::resolveWhatWgParts(self::whatWgState($base), $ref);
        if (null === $resolved) {
            throw new \Uri\WhatWg\InvalidUrlException('Unable to resolve URL');
        }

        return self::newWhatWgUrlVariable($ctx, $resolved);
    }

    /** Copy a registered unit-enum case into $dest (or null when missing). */
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

    public static function hasWhatWgState(ObjectEntry $object): bool
    {
        return isset(self::$whatWgState[$object->id]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function bindWhatWgState(ObjectEntry $object, array $state): void
    {
        self::$whatWgState[$object->id] = $state;
        $object->constructed = true;
    }

    public static function whatWgState(ObjectEntry $object): array
    {
        return self::$whatWgState[$object->id] ?? throw new \LogicException('Url state missing');
    }

    public static function rfc3986Resolve(Context $ctx, ObjectEntry $base, string $ref): Variable
    {
        $resolved = self::resolveWhatWgParts(self::rfc3986State($base), $ref);
        if (null === $resolved) {
            throw new \Uri\InvalidUriException('Unable to resolve URI');
        }

        return self::newRfc3986UriVariable($ctx, $resolved);
    }

    public static function emptyBuilderState(): array
    {
        return [
            'scheme' => null,
            'username' => null,
            'password' => null,
            'userinfo' => null,
            'host' => null,
            'port' => null,
            'path' => '',
            'query' => null,
            'fragment' => null,
        ];
    }

    public static function newUriBuilderVariable(Context $ctx): Variable
    {
        $class = self::requireClass($ctx, self::CLASS_RFC3986_URI_BUILDER, 'Uri\\Rfc3986\\UriBuilder');
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$builderState[$entry->id] = self::emptyBuilderState();
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function builderState(ObjectEntry $object): array
    {
        return self::$builderState[$object->id] ?? throw new \LogicException('UriBuilder state missing');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function builderApply(ObjectEntry $object, array $overrides): void
    {
        $state = self::builderState($object);
        foreach ($overrides as $key => $value) {
            $state[$key] = $value;
        }
        if (\array_key_exists('userinfo', $overrides)) {
            $ui = $overrides['userinfo'];
            if (null === $ui || '' === $ui) {
                $state['username'] = null;
                $state['password'] = null;
                $state['userinfo'] = null;
            } elseif (\is_string($ui)) {
                $colon = strpos($ui, ':');
                if (false === $colon) {
                    $state['username'] = $ui;
                    $state['password'] = null;
                } else {
                    $state['username'] = substr($ui, 0, $colon);
                    $state['password'] = substr($ui, $colon + 1);
                }
                $state['userinfo'] = self::composeUserinfo($state['username'], $state['password']);
            }
        }
        if (\array_key_exists('scheme', $overrides) && \is_string($state['scheme'])) {
            $state['scheme'] = strtolower($state['scheme']);
        }
        if (\array_key_exists('host', $overrides)) {
            $rawHost = $overrides['host'];
            $state = self::applyHostOverride($state, \is_string($rawHost) ? $rawHost : null);
        }
        self::$builderState[$object->id] = $state;
    }

    public static function builderReset(ObjectEntry $object): void
    {
        self::$builderState[$object->id] = self::emptyBuilderState();
    }

    public static function builderBuild(Context $ctx, ObjectEntry $builder, ?ObjectEntry $baseUri): Variable
    {
        $state = self::builderState($builder);
        if (null !== $baseUri) {
            $empty = self::emptyBuilderState();
            $merged = self::rfc3986State($baseUri);
            foreach ($state as $key => $value) {
                if (($empty[$key] ?? null) !== $value) {
                    $merged[$key] = $value;
                }
            }
            $merged['userinfo'] = self::composeUserinfo(
                isset($merged['username']) && \is_string($merged['username']) ? $merged['username'] : null,
                isset($merged['password']) && \is_string($merged['password']) ? $merged['password'] : null
            );
            $state = $merged;
        }

        return self::newRfc3986UriVariable($ctx, $state);
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
