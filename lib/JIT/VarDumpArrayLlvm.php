<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_dump() on {@see __hashtable__*} (#34498).
 *
 * Thin standalone AOT {@see Builtin\StringVarDump::implementThinScalarBridge} aborted
 * non-scalars via emitThinUnsupportedAbort (#23540). Peer of {@see SerializeArrayLlvm} /
 * {@see JsonEncodeArrayLlvm}: walk export pairs and echo Zend php_var_dump shape.
 *
 * php-src: ext/standard/var.c — php_var_dump / php_array_element_dump
 */
final class VarDumpArrayLlvm
{
    private static int $seq = 0;

    /**
     * Echo `array(N) { … }` at dump level $level (php-src COMMON indent via caller).
     *
     * Nested values recurse through `__compiler_var_dump_ex(val, level+2)`.
     */
    public static function dump(Context $context, Value $ht, Value $level): void
    {
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) ++self::$seq;

        ValueEchoHelper::echoLiteral($context, 'array(');
        $numI64 = $context->builder->zExt($num, $i64);
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_ll'), $numI64);
        ValueEchoHelper::echoLiteral($context, ") {\n");

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'vd_ht_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'vd_ht_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_ht_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyBox);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valBox);

        // php_array_element_dump: "%*c" with level+1; recurse level+2 (#23726).
        $keyIndent = $context->builder->addNoSignedWrap($level, $i64->constInt(1, false));
        self::echoSpaces($context, $keyIndent);

        $valueMap = $context->structFieldMap['__value__'];
        $keyKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($keyPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isLongKey = $context->builder->icmp(
            Builder::INT_EQ,
            $keyKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );

        $keyStrBlock = BasicBlockHelper::append($context, 'vd_ht_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'vd_ht_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'vd_ht_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        ValueEchoHelper::echoLiteral($context, '[');
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_ll'), $keyLong);
        ValueEchoHelper::echoLiteral($context, "]=>\n");
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        ValueEchoHelper::echoLiteral($context, '["');
        ValueEchoHelper::echoStringVariable(
            $context,
            new JitVariable(
                $context,
                JitVariable::TYPE_STRING,
                JitVariable::KIND_VALUE,
                $rawKey
            )
        );
        ValueEchoHelper::echoLiteral($context, "\"]=>\n");
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $childLevel = $context->builder->addNoSignedWrap($level, $i64->constInt(2, false));
        $context->builder->call(
            $context->lookupFunction('__compiler_var_dump_ex'),
            $valPtr,
            $childLevel
        );

        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        // Closing brace indent: spaces(level-1) when level > 1 (VmVarDump::dumpArray).
        $needCloseIndent = $context->builder->icmp(
            Builder::INT_SGT,
            $level,
            $i64->constInt(1, false)
        );
        $closeIndent = BasicBlockHelper::append($context, 'vd_ht_close_indent_'.$tag);
        $closeBrace = BasicBlockHelper::append($context, 'vd_ht_close_brace_'.$tag);
        $context->builder->branchIf($needCloseIndent, $closeIndent, $closeBrace);
        $context->builder->positionAtEnd($closeIndent);
        $closeSpaces = $context->builder->sub($level, $i64->constInt(1, false));
        self::echoSpaces($context, $closeSpaces);
        $context->builder->branch($closeBrace);
        $context->builder->positionAtEnd($closeBrace);
        ValueEchoHelper::echoLiteral($context, "}\n");
    }

    /** NestedJIT-safe indent without str_repeat (#23540 / peer PackEngineEncode #22981). */
    public static function echoSpaces(Context $context, Value $nI64): void
    {
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) ++self::$seq;
        $nSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($nI64, $nSlot);

        $head = BasicBlockHelper::append($context, 'vd_spaces_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'vd_spaces_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_spaces_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $n = $context->builder->load($nSlot);
        $gt = $context->builder->icmp(Builder::INT_SGT, $n, $i64->constInt(0, false));
        $context->builder->branchIf($gt, $body, $done);

        $context->builder->positionAtEnd($body);
        ValueEchoHelper::echoLiteral($context, ' ');
        $context->builder->store(
            $context->builder->sub($n, $i64->constInt(1, false)),
            $nSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
