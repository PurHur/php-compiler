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
        ?string $postBody = null
    ): void {
        self::$activeContext = $context;
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
        self::populateServer($context, $queryString, $postBody);
        self::populateRequest($context);
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
        self::setStringEntry($server, 'SERVER_SOFTWARE', 'PHP-Compiler-VM');

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                self::setStringEntry($server, $key, $value);
            }
        }
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
        foreach ($params as $key => $value) {
            if (!is_string($key) || is_array($value)) {
                continue;
            }
            self::setStringEntry($ht, $key, (string) $value);
        }
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
