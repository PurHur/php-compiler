<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Builtin\StringVarDump;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_dump() object formatting (#34506).
 *
 * Thin AOT {@see StringVarDump} aborted non-enum TYPE_OBJECT (#23540). Peer of
 * {@see VarDumpArrayLlvm} / {@see VarExportObjectLlvm}: echo an already extracted
 * class name + property HT into Zend `object(class)#N (count) { … }` text.
 *
 * Thin standalone AOT hardcodes object handle `#1` (single-process scripts).
 * Props must be extracted at the call site via {@see \PHPCompiler\ext\standard\JitGetObjectVars}
 * so `(object)[…]` defineProperty is visible (#34506).
 *
 * php-src: ext/standard/var.c — php_var_dump / php_object_property_dump
 */
final class VarDumpObjectLlvm
{
    private static int $seq = 0;

    /**
     * Echo `object(className)#1 (N) { … }` at dump level $level.
     */
    public static function dump(Context $context, Value $className, Value $ht, Value $level): void
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

        ValueEchoHelper::echoLiteral($context, 'object(');
        ValueEchoHelper::echoStringVariable(
            $context,
            new JitVariable(
                $context,
                JitVariable::TYPE_STRING,
                JitVariable::KIND_VALUE,
                $className
            )
        );
        ValueEchoHelper::echoLiteral($context, ')#1 (');
        $numI64 = $context->builder->zExt($num, $i64);
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_ll'), $numI64);
        ValueEchoHelper::echoLiteral($context, ") {\n");

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'vd_obj_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'vd_obj_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_obj_done_'.$tag);
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

        $keyIndent = $context->builder->addNoSignedWrap($level, $i64->constInt(1, false));
        VarDumpArrayLlvm::echoSpaces($context, $keyIndent);

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

        $keyStrBlock = BasicBlockHelper::append($context, 'vd_obj_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'vd_obj_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'vd_obj_key_done_'.$tag);
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
        $needCloseIndent = $context->builder->icmp(
            Builder::INT_SGT,
            $level,
            $i64->constInt(1, false)
        );
        $closeIndent = BasicBlockHelper::append($context, 'vd_obj_close_indent_'.$tag);
        $closeBrace = BasicBlockHelper::append($context, 'vd_obj_close_brace_'.$tag);
        $context->builder->branchIf($needCloseIndent, $closeIndent, $closeBrace);
        $context->builder->positionAtEnd($closeIndent);
        $closeSpaces = $context->builder->sub($level, $i64->constInt(1, false));
        VarDumpArrayLlvm::echoSpaces($context, $closeSpaces);
        $context->builder->branch($closeBrace);
        $context->builder->positionAtEnd($closeBrace);
        ValueEchoHelper::echoLiteral($context, "}\n");
    }

    /**
     * Extract class name + props from a boxed object value, then dump.
     * Used for nested objects and the thin-bridge object arm (#34506).
     */
    public static function dumpFromValueBox(Context $context, Value $valuePtr, Value $level): void
    {
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objPtr);
        $className = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );

        $context->builder->call(
            $context->lookupFunction(StringVarDump::OBJ_ABI),
            $className,
            $ht,
            $level
        );
    }
}
