<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\JIT\Builtin\LibxmlUseInternalErrorsRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for libxml_get_errors() (#29161).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_get_errors)
 */
final class JitLibxmlGetErrors
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'libxml_get_errors() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        LibxmlUseInternalErrorsRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        // hrtime_pair peer: HT built inside ABI fn, not inlined at the call site.
        $ht = $context->builder->call($context->lookupFunction('__compiler_libxml_get_errors'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $slotPtr,
            $ht
        );

        return $slotPtr;
    }
}
