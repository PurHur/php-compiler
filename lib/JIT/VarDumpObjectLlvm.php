<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_dump() on non-enum objects (#34506).
 *
 * Thin AOT {@see Builtin\StringVarDump} aborted non-enum TYPE_OBJECT.
 * Peer of {@see VarDumpArrayLlvm}: `object(Name)#id (N) {…}` via get_object_vars.
 *
 * Object ids: process-global counter (Zend objects_store style for cold scripts).
 *
 * php-src: ext/standard/var.c — php_var_dump object branch
 */
final class VarDumpObjectLlvm
{
    private static int $seq = 0;

    private const NEXT_ID_GLOBAL = '__phpc_var_dump_next_object_id';

    /**
     * Echo object dump at $level (caller already emitted COMMON indent when level>1).
     *
     * @param Value $valuePtr `__value__*` typed as TYPE_OBJECT
     * @param Value $level    native int64 dump level
     */
    public static function dump(Context $context, Value $valuePtr, Value $level): void
    {
        $objVar = new JitVariable(
            $context,
            JitVariable::TYPE_VALUE,
            JitVariable::KIND_VALUE,
            $valuePtr
        );
        $className = ReflectionBuiltinHelper::getDebugTypeClassName($context, $objVar);
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );

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

        $objId = self::nextObjectDebugId($context);

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
        ValueEchoHelper::echoLiteral($context, ')#');
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_ll'), $objId);
        ValueEchoHelper::echoLiteral($context, ' (');
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

    private static function nextObjectDebugId(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal(self::NEXT_ID_GLOBAL);
        if (null === $global) {
            $global = $context->module->addGlobal($i64, self::NEXT_ID_GLOBAL);
            $global->setInitializer($i64->constInt(1, false));
        }
        $cur = $context->builder->load($global);
        $context->builder->store(
            $context->builder->addNoSignedWrap($cur, $i64->constInt(1, false)),
            $global
        );

        return $cur;
    }
}
