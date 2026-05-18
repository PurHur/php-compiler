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

    public static function populateFromEnvironment(Context $context, ?string $queryString = null): void
    {
        if (null === $queryString) {
            $fromEnv = getenv('QUERY_STRING');
            $queryString = false === $fromEnv ? '' : $fromEnv;
        }
        self::populateGet($context, $queryString);
    }

    private static function populateGet(Context $context, string $queryString): void
    {
        $get = $context->ensureSuperglobal('_GET');
        if ('' === $queryString) {
            return;
        }
        $params = [];
        parse_str($queryString, $params);
        $ht = $get->toArray();
        foreach ($params as $key => $value) {
            if (!is_string($key) || is_array($value)) {
                continue;
            }
            $v = new Variable(Variable::TYPE_STRING);
            $v->string((string) $value);
            $ht->add($key, $v);
        }
    }
}
