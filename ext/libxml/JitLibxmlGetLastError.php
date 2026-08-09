<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LibxmlUseInternalErrorsRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for libxml_get_last_error() (#29161).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_get_last_error)
 */
final class JitLibxmlGetLastError
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'libxml_get_last_error() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        LibxmlUseInternalErrorsRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $obj = $context->builder->call($context->lookupFunction('__compiler_libxml_get_last_error'));

        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $obj, $objPtrTy->constNull());

        $falseBb = BasicBlockHelper::append($context, 'libxml_get_last_error_false');
        $objBb = BasicBlockHelper::append($context, 'libxml_get_last_error_obj');
        $doneBb = BasicBlockHelper::append($context, 'libxml_get_last_error_done');
        $context->builder->branchIf($isNull, $falseBb, $objBb);

        $context->builder->positionAtEnd($falseBb);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($objBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $slotPtr,
            $obj
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slotPtr;
    }
}
