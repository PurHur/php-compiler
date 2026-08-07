<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

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
     *
     * The value-box stores an i8 tag; ABI bridges may sign-extend (TYPE_HASHTABLE 135 → -121).
     * Normalize before comparing (#23971 e08_spread).
     *
     * NestedJIT-safe: use integer literals only for the HT tag. Cross-class consts
     * and even private class consts do not fold under NestedJIT, so the HT arm became
     * a broken value-box compare and AOT array-literal unpack aborted after
     * writeHashtable stored tag 135 (#28641).
     */
    public static function valueBoxIsArray(int $typeByte): bool
    {
        $typeByte &= 0xff;
        // 6 = Variable::TYPE_ARRAY; 135 = JIT TYPE_HASHTABLE (7|1<<7) & 0xff
        if (6 === $typeByte) {
            return true;
        }
        if (135 === $typeByte) {
            return true;
        }

        return false;
    }

    public static function valueBoxIsString(int $typeByte): bool
    {
        $typeByte &= 0xff;
        // 4 = Variable::TYPE_STRING; 132 = JIT TYPE_STRING (4|1<<7) & 0xff
        if (4 === $typeByte) {
            return true;
        }
        if (132 === $typeByte) {
            return true;
        }

        return false;
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