<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ObOutput;
use PHPCompiler\JIT\Builtin\ObOutputEchoJitEmit;
use PHPCompiler\JIT\Builtin\ObOutputExecCaptureRuntime;
use PHPCompiler\JIT\Builtin\ObOutputJitBridge;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;

/**
 * User-script standalone AOT: exec-capture ob stack + append-bytes echo (#19422, #13571, #10492).
 *
 * Quarantined from {@see ObOutputJitBridge} to keep bridge LOC under shrink guard (#12999).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19389 / #19399.
 * php-src: ext/standard/output.c — ob_* / output buffering
 */
final class JitObOutputKernel
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
        ObOutputJitBridge::finishUserScriptEmit($context);
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
