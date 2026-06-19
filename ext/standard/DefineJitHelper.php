<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * User constant table factory for compiled JIT/AOT modules (#9410, php-in-PHP).
 *
 * VM SSOT remains {@see \PHPCompiler\VM\Context::defineConstant}; JIT/AOT cache the
 * {@see HashTable} singleton in {@see \PHPCompiler\JIT\Builtin\DefineRuntime}.
 * php-src: Zend/zend_constants.c — EG(user_constant_table)
 */
final class DefineJitHelper
{
    public static function createTable(): HashTable
    {
        return new HashTable();
    }
}
