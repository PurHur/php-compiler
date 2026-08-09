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
 * LLVM lowering for libxml_clear_errors() via LibxmlInternalErrorsJitHelper (#29161).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_clear_errors)
 */
final class JitLibxmlClearErrors
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'libxml_clear_errors() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        LibxmlUseInternalErrorsRuntime::ensureLinked($context);
        $context->builder->call($context->lookupFunction('__compiler_libxml_clear_errors'));

        // void builtin — peer error_clear_last (#3158).
        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
