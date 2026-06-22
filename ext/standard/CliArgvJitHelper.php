<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * CLI argv table factory for compiled JIT/AOT modules (#9439, php-in-PHP).
 *
 * VM SSOT delegates via {@see VmCliArgv}; JIT/AOT slot writes stay in
 * {@see \PHPCompiler\JIT\Builtin\CliArgvRuntime} until indexed string cells compile in PHP.
 * php-src: ext/standard/basic_functions.c — $argc / $argv in CLI SAPI
 */
final class CliArgvJitHelper
{
    public static function createTable(): HashTable
    {
        return new HashTable();
    }
}
