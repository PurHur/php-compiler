<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: thin __string__htmlspecialchars without nested JIT (#13571, #15417).
 *
 * Smoke examples use ASCII names without entities; delegate to __string__separate (php-src ENT_QUOTES
 * subset deferred — full scan in {@see StringHtmlspecialcharsUserScriptLlvm} when needed).
 * php-src: ext/standard/html.c
 */
final class StringHtmlspecialcharsUserScriptLlvm
{
    public static function shouldUse(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }
        foreach (
            [
                'PHP_COMPILER_AOT_USER_SCRIPT',
                'PHP_COMPILER_BOOTSTRAP_AOT_LINK',
            ] as $key
        ) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }

    public static function implement(Context $context): void
    {
        $abiName = '__string__htmlspecialchars';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        if ($fn->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $fn);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        $entry = $fn->appendBasicBlock('htmlspecialchars_user_stub_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $fn->getParam(0)
            )
        );
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
        $context->builder->clearInsertionPosition();
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
