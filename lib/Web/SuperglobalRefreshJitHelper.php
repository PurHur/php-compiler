<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\VM\HashTable;

/**
 * CGI superglobal refresh for compiled JIT/AOT standalone modules (#9907, php-in-PHP).
 *
 * SSOT: {@see Superglobals}
 * php-src: main/php_variables.c — php_register_variable, SAPI superglobal registration
 */
final class SuperglobalRefreshJitHelper
{
    public static function buildGetTable(): HashTable
    {
        return Superglobals::buildGetTableForRefresh();
    }

    public static function buildPostTable(): HashTable
    {
        return Superglobals::buildPostTableForRefresh();
    }

    public static function buildFilesTable(): HashTable
    {
        return Superglobals::buildFilesTableForRefresh();
    }

    public static function buildRequestTable(): HashTable
    {
        return Superglobals::buildRequestTableForRefresh();
    }

    public static function buildServerTable(): HashTable
    {
        return Superglobals::buildServerTableForRefresh(true);
    }

    public static function buildCookieTable(): HashTable
    {
        return Superglobals::buildCookieTableForRefresh();
    }
}
