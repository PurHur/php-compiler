<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT exec capture without nested ProcessExecCaptureNativeJitHelper (#10492, #15407).
 *
 * Builds the exec()/system()/passthru() capture hashtable from {@see __compiler_shell_exec}
 * plus __hashtable__* LLVM ops. php-src: ext/standard/exec.c
 */
final class ProcessExecCaptureLlvm
{
    public static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_process_exec_capture';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        ProcessRuntime::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $context->context->functionType($htPtr, false, $strPtr));

        $entry = $fn->appendBasicBlock('pec_llvm_entry');
        $fail = $fn->appendBasicBlock('pec_llvm_fail');
        $ok = $fn->appendBasicBlock('pec_llvm_ok');
        $context->builder->positionAtEnd($entry);
        $output = $context->builder->call(
            $context->lookupFunction('__compiler_shell_exec'),
            $fn->getParam(0)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $output, $strPtr->constNull()),
            $fail,
            $ok
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($ok);
        $line = self::rtrimCrLf($context, $output);
        $linesHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $linesHt,
            $sizeT->constInt(0, false),
            $line
        );
        $resultHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $resultHt,
            self::literalKey($context, 'lines'),
            $linesHt
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $resultHt,
            self::literalKey($context, 'status'),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->returnValue($resultHt);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function rtrimCrLf(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $data = $context->builder->structGep($str, $map['value']);
        $loop = BasicBlockHelper::append($context, 'pec_rtrim_loop');
        $check = BasicBlockHelper::append($context, 'pec_rtrim_check');
        $done = BasicBlockHelper::append($context, 'pec_rtrim_done');
        $trim = BasicBlockHelper::append($context, 'pec_rtrim_trim');
        $lenAlloca = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($len, $lenAlloca);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $curLen = $context->builder->load($lenAlloca);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $curLen, $i64->constInt(0, false)),
            $check,
            $done
        );

        $context->builder->positionAtEnd($check);
        $lastIdx = $context->builder->sub($curLen, $i64->constInt(1, false));
        $lastByte = $context->builder->load($context->builder->inBoundsGEP($data, $lastIdx));
        $isCrLf = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $lastByte, $i8->constInt(10, false)),
            $context->builder->icmp(Builder::INT_EQ, $lastByte, $i8->constInt(13, false))
        );
        $context->builder->branchIf($isCrLf, $trim, $done);

        $context->builder->positionAtEnd($trim);
        $context->builder->store($lastIdx, $lenAlloca);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($done);
        $finalLen = $context->builder->load($lenAlloca);
        $trimmed = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $context->builder->pointerCast($data, $context->getTypeFromString('int8*'))
        );

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $trimmed
        );
    }

    private static function literalKey(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }
}
