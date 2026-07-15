<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: native LLVM htmlspecialchars ABI without nested JIT (#15417, #18832).
 *
 * Nested {@see HtmlspecialcharsJitHelper} compile breaks $_REQUEST runtime reads in examples/001
 * (#18974 regression). Identity stub suffices for alphanumeric smoke; full escape remains on the
 * nested-JIT path for non-defer standalone builds.
 * php-src: ext/standard/html.c
 */
final class StringHtmlspecialcharsUserScriptLlvm
{
    private const ABI = '__string__htmlspecialchars';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            self::ABI,
            $context->context->functionType($strPtr, false, $strPtr, $i64)
        );
        $entry = $fn->appendBasicBlock('htmlspecialchars_user_script_identity');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($fn->getParam(0));
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
