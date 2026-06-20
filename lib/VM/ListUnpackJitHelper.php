<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * Lowered into JIT/AOT modules for list/spread unpack guards (#10221, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_FE_FETCH, list assign
 * SSOT: {@see \PHPCompiler\JIT\ListUnpackHelper} guard messages
 */
final class ListUnpackJitHelper
{
    /**
     * Value-box operand is array when type tag is VM array or JIT hashtable (#9248).
     */
    public static function valueBoxIsArray(int $typeByte): bool
    {
        return Variable::TYPE_ARRAY === $typeByte
            || JitVariable::TYPE_HASHTABLE === $typeByte;
    }

    public static function valueBoxIsString(int $typeByte): bool
    {
        return Variable::TYPE_STRING === $typeByte;
    }

    /**
     * Runtime list-destruct unpackable guard for value-box operands (#4325, #4308).
     *
     * ArrayAccess containers are always unpackable at this guard; iterable materialization
     * is handled separately in the VM list-unpack opcode path.
     */
    public static function valueBoxIsListDestructUnpackable(int $typeByte, bool $implementsArrayAccess): bool
    {
        if ($implementsArrayAccess) {
            return true;
        }

        return self::valueBoxIsArray($typeByte);
    }
}
