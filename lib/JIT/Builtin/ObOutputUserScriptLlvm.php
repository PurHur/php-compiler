<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;

/**
 * User-script standalone AOT: direct STDOUT echo without nested ObOutputJitHelper JIT (#13571, #13822).
 *
 * Quarantined from {@see ObOutputJitBridge} to keep bridge LOC under shrink guard (#12999).
 */
final class ObOutputUserScriptLlvm
{
    public static function shouldUse(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $userScript && 'true' !== strtolower((string) $userScript)) {
            return false;
        }

        return true;
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        self::ensureWriteLibc($context);
        ObOutput::registerExternals($context);
        EmbedObEchoBridge::implementAll($context);
        ObOutputJitBridge::finishUserScriptEmit($context);
        self::restoreInsertBlock($context, $restore);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureWriteLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        try {
            $context->lookupFunction('write');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'write',
                $context->context->functionType($i64, false, $i32, $i8p, $sizeT)
            );
            $context->registerFunction('write', $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
