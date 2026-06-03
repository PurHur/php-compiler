<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __phpc_char_in_mask for trim/ltrim/rtrim masks (#3709, #4646).
 *
 * php-src: ext/standard/string.c php_charmask subset (literal mask bytes).
 */
final class StringTrimMask
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_char_in_mask');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i32, false, $i32, $strPtrTy);
        $fn = $context->module->addFunction('__phpc_char_in_mask', $ft);
        self::implementCharInMask($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementCharInMask(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('char_in_mask_entry');
        $context->builder->positionAtEnd($entry);

        $ch = $fn->getParam(0);
        $mask = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $emptyBlock = $fn->appendBasicBlock('char_in_mask_empty');
        $loopHead = $fn->appendBasicBlock('char_in_mask_head');
        $loopBody = $fn->appendBasicBlock('char_in_mask_body');
        $loopInc = $fn->appendBasicBlock('char_in_mask_inc');
        $foundBlock = $fn->appendBasicBlock('char_in_mask_found');
        $notFoundBlock = $fn->appendBasicBlock('char_in_mask_miss');
        $oneI64 = $i64->constInt(1, false);

        $maskLen = $context->builder->load($context->builder->structGep($mask, $map['length']));
        $iSlot = $context->builder->alloca($i64, 1, 'char_in_mask_i');
        $context->builder->store($zeroI64, $iSlot);
        $maskChars = $context->builder->structGep($mask, $map['value']);
        $chByte = $context->builder->trunc($ch, $i8);
        $nonPositive = $context->builder->icmp(Builder::INT_SLE, $maskLen, $zeroI64);
        $context->builder->branchIf($nonPositive, $emptyBlock, $loopHead);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $maskLen);
        $context->builder->branchIf($atEnd, $notFoundBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $maskByte = $context->builder->load($context->builder->gep($maskChars, $i));
        $matches = $context->builder->icmp(Builder::INT_EQ, $maskByte, $chByte);
        $context->builder->branchIf($matches, $foundBlock, $loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $oneI64),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->returnValue($oneI32);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($notFoundBlock);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_char_in_mask');
        if (null === $fn) {
            throw new \LogicException('__phpc_char_in_mask missing after trim mask LLVM implement');
        }
        $context->registerFunction('__phpc_char_in_mask', $fn);
    }
}
