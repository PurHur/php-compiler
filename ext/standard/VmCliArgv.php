<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * CLI $argc/$argv hashtable materialization SSOT (#9439, php-in-PHP).
 *
 * VM: {@see \PHPCompiler\Web\Superglobals::populateCliArgv}
 * JIT/AOT: {@see CliArgvJitHelper} via {@see \PHPCompiler\JIT\Builtin\CliArgvRuntime}
 * php-src: ext/standard/basic_functions.c — $argc / $argv in CLI SAPI
 */
final class VmCliArgv
{
    /**
     * @param list<string> $argv script name at index 0
     */
    public static function buildArgvTable(array $argv): HashTable
    {
        $ht = new HashTable();
        foreach ($argv as $i => $arg) {
            $slot = new Variable();
            $slot->string((string) $arg);
            $ht->addIndex((int) $i, $slot);
        }

        return $ht;
    }
}
