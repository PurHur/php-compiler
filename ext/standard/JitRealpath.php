<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for realpath() via libc realpath(3).
 *
 * Failure returns an empty string; PHP's empty string compares equal to false with ==.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitRealpath
{
    private static int $blockSerial = 0;

    public static function resolve(Context $context, Value $str): Value
    {
        $fn = self::ensureResolveStandalone($context);

        return $context->builder->call($fn, $str);
    }

    /** Standalone realpath helper — same {main} miscompile class as {@see JitStat::ensureLoadModeStandalone}. */
    private static function ensureResolveStandalone(Context $context): Value
    {
        $name = '__phpc_jit_realpath';
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return $existing;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, $strPtr)
        );
        $entry = $fn->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($entry);
        $result = self::resolveInline($context, $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function resolveInline(Context $context, Value $str): Value
    {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $dotStr = $context->builder->load($context->constantStringFromString('.'));
        $str = $context->builder->select($isEmpty, $dotStr, $str);
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $charPtr = $context->getTypeFromString('char*');
        $null = $charPtr->constNull();
        $resolved = $context->builder->call(
            $context->lookupFunction('realpath'),
            $pathPtr,
            $null
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $null);

        $failBlock = BasicBlockHelper::append($context, 'realpath_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'realpath_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'realpath_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $resolved
        );
        $lenI64 = $context->builder->zExt($len, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $resolved
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $resolved);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($str->typeOf());
        $phi->addIncoming($emptyStr, $failBlock);
        $phi->addIncoming($resultStr, $okBlock);

        // Seal the merge block so later compares/ternaries do not append to it.
        $resultSlot = $context->builder->alloca($str->typeOf(), 1, 'realpath_result_'.$id);
        $context->builder->store($phi, $resultSlot);
        $contBlock = BasicBlockHelper::append($context, 'realpath_cont_'.$id);
        $context->builder->branch($contBlock);

        $context->builder->positionAtEnd($contBlock);

        return $context->builder->load($resultSlot);
    }
}
