<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * LLVM __string__bitwiseNot — per-byte ~ for Zend unary ~ on string operands (#4998).
 */
final class StringBitwiseNot
{
    public static function register(Context $context): void
    {
        $fnType = $context->context->functionType(
            $context->getTypeFromString('__string__*'),
            false,
            $context->getTypeFromString('__string__*')
        );
        $fn = $context->module->addFunction('__string__bitwiseNot', $fnType);
        $fn->addAttributeAtIndex(\PHPLLVM\Attribute::INDEX_FUNCTION, $context->attributes['alwaysinline']);
        $context->registerFunction('__string__bitwiseNot', $fn);
    }

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__bitwiseNot');
        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $mask = $i64->constInt(255, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $srcChars = $context->builder->structGep($src, $map['value']);
        $destChars = $context->builder->structGep($dest, $map['value']);

        $loopHeader = $fn->appendBasicBlock('bitwise_not_loop');
        $loopBody = $fn->appendBasicBlock('bitwise_not_body');
        $loopDone = $fn->appendBasicBlock('bitwise_not_done');

        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($loopHeader);
        $i = $context->builder->load($iSlot);
        $continue = $context->builder->icmp(Builder::INT_SLT, $i, $len);
        $context->builder->branchIf($context->castToBool($continue), $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $srcAt = $context->builder->gep($srcChars, $i);
        $ch = $context->builder->load($srcAt);
        $wide = $context->builder->zExt($ch, $i64);
        $inverted = $context->builder->xor($wide, $mask);
        $outByte = $context->builder->trunc($inverted, $i8);
        $destAt = $context->builder->gep($destChars, $i);
        $context->builder->store($outByte, $destAt);
        $next = $context->builder->addNoSignedWrap($i, $one);
        $context->builder->store($next, $iSlot);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($dest);
        $context->builder->clearInsertionPosition();
    }
}
