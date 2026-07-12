<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;

/**
 * User-script standalone AOT: exec-capture ob stack + append-bytes echo (#13571, #10492).
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
        $restore = self::captureInsertBlock($context);
        ObOutputJitBridge::prepareUserScriptEmit($context);
        ObOutput::registerExternals($context);
        ObOutputExecCaptureRuntime::ensureLinked($context);
        ObOutputEchoJitEmit::implementAll($context);
        self::restoreInsertBlock($context, $restore);
        if (null === $restore) {
            $context->builder->clearInsertionPosition();
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
