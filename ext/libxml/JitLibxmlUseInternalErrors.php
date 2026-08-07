<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\LibxmlUseInternalErrorsRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for libxml_use_internal_errors() via LibxmlInternalErrorsJitHelper (#28659).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_use_internal_errors)
 */
final class JitLibxmlUseInternalErrors
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'libxml_use_internal_errors() expects at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $i32 = $context->getTypeFromString('int32');
        $hasNew = $i32->constInt(0, false);
        $newValue = $i32->constInt(0, false);
        if (1 === $argc && JITVariable::TYPE_NULL !== $args[0]->type && !$args[0]->isNullConstant) {
            $hasNew = $i32->constInt(1, false);
            $bool = JitBoolArg::lower(
                $context,
                $args[0],
                'libxml_use_internal_errors(): Argument #1 ($use_errors)'
            );
            $newValue = $context->builder->zext($bool, $i32);
        }

        LibxmlUseInternalErrorsRuntime::ensureLinked($context);
        $prev = $context->builder->call(
            $context->lookupFunction('__compiler_libxml_use_internal_errors'),
            $hasNew,
            $newValue
        );
        $i1 = $context->builder->icmp(
            Builder::INT_NE,
            $prev,
            $i32->constInt(0, false)
        );

        // Standalone AOT: bare int1 bool returns mis-lower in ?: / if (issue #15704).
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return $i1;
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $i1);

        return $context->builder->load($slot);
    }
}
