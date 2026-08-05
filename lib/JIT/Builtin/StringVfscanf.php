<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_vfscanf via fgets + __compiler_sscanf (#12541, #25718, #27663).
 *
 * Prefer {@see \PHPCompiler\ext\standard\JitVfscanf} emitting fgets+sscanf in the **user**
 * function — NestedJIT parseAssignMeta aborts when entered from this ABI with outCount>0
 * (#27663). This ABI remains for inventory/ensureLinked callers.
 *
 * Do **not** NestedJIT VmVfscanf/VmFs with live StreamIo (#27663 module verify).
 * Thin AOT: libc fgets/fseek/ftell via {@see StreamReadRuntime::forceLibcStreamPositionAbis}.
 * php-src: ext/standard/file.c fscanf → php_stream_get_line + php_sscanf_internal
 */
final class StringVfscanf
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_vfscanf');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        if ($context->isThinStandaloneAotMain()) {
            StreamReadRuntime::forceLibcStreamPositionAbis($context);
        } else {
            StreamReadRuntime::ensureVfscanfAbi($context);
        }

        StringSscanfByRef::ensureLinked($context);
        self::implementVfscanfBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementVfscanfBridge(Context $context): void
    {
        $abiName = '__compiler_vfscanf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $ft = $context->context->functionType(
            $i64,
            false,
            $i64,
            $strPtr,
            $i64,
            $valuePtrPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('vfscanf_byref_entry');
        $fail = $fn->appendBasicBlock('vfscanf_byref_fail');
        $gotLine = $fn->appendBasicBlock('vfscanf_byref_got_line');
        $scan = $fn->appendBasicBlock('vfscanf_byref_scan');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $fmt = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $minusOne = $i64->constInt(-1, true);

        $nullFmt = $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull());
        $context->builder->branchIf($nullFmt, $fail, $gotLine);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($gotLine);
        $line = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            $i64->constInt(-1, true)
        );
        $lineNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $nonNull = $fn->appendBasicBlock('vfscanf_byref_non_null');
        $context->builder->branchIf($lineNull, $fail, $nonNull);

        $context->builder->positionAtEnd($nonNull);
        $lineLen = $context->builder->call($context->lookupFunction('__string__strlen'), $line);
        $empty = $context->builder->icmp(Builder::INT_EQ, $lineLen, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $fail, $scan);

        $context->builder->positionAtEnd($scan);
        $assigned = $context->builder->call(
            $context->lookupFunction('__compiler_sscanf'),
            $line,
            $fmt,
            $outCount,
            $outPtrs
        );
        $context->builder->returnValue($assigned);
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_vfscanf');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__compiler_vfscanf missing after StringVfscanf bridge (#12541)');
        }
        $context->registerFunction('__compiler_vfscanf', $fn);
    }
}
