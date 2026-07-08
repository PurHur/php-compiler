<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;

/**
 * LLVM implementation of __compiler_wordwrap for user-script standalone AOT (#16734, #17349).
 *
 * Nested WordwrapJitHelper segfaults in minimal standalone init; restore pre-#14565 LLVM.
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
final class StringWordwrapLlvm
{
    private const ABI = '__compiler_wordwrap';

    private const LLVM_ENTRY = 'wordwrap_llvm_main';

    public static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $strPtr, $i8);
        $fn = $context->module->getNamedFunction(self::ABI);
        if (null === $fn) {
            $fn = $context->module->addFunction(self::ABI, $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, self::LLVM_ENTRY)) {
            $context->registerFunction(self::ABI, $fn);

            return;
        }

        $savedBlock = self::captureInsertBlock($context);

        $entry = $fn->appendBasicBlock(self::LLVM_ENTRY);
        $context->builder->positionAtEnd($entry);
        $result = WordwrapLlvmEmit::wrap(
            $context,
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);

        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
