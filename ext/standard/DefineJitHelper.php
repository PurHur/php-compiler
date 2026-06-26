<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

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

    /** Zend defined() / define() duplicate guard on user constant table (#10031). */
    public static function isDefined(HashTable $table, string $name): bool
    {
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($name);

        return $table->offsetIsSet($key);
    }
}
