<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;
use PHPCompiler\VM\Context as VMContext;

/**
 * PHP 8.5+ compile-time E_DEPRECATED for the backtick shell-exec operator (#26280).
 *
 * php-src: Zend/zend_compile.c zend_compile_shell_exec — "The backtick (`) operator is
 * deprecated, use shell_exec() instead". Explicit shell_exec() calls are unaffected.
 */
final class BacktickShellExecDeprecation
{
    public const MESSAGE = 'The backtick (`) operator is deprecated, use shell_exec() instead';

    public static function emitForSource(string $code, string $filename, VMContext $context): void
    {
        if (!CompilerVersion::supportsBacktickShellExecDeprecation()) {
            return;
        }
        if (!str_contains($code, '`')) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return;
        }
        if (!\function_exists('token_get_all')) {
            return;
        }

        $frame = self::resolveEmitFrame($context, $filename);
        $tokens = token_get_all($code);
        $n = \count($tokens);
        $inShell = false;

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            if (\is_array($tok)) {
                continue;
            }
            if ('`' !== $tok) {
                continue;
            }
            if ($inShell) {
                $inShell = false;
                continue;
            }
            $inShell = true;
            $context->errors->internalDeprecated(
                self::MESSAGE,
                $context,
                $frame,
                $filename,
                self::lineForIndex($tokens, $i)
            );
        }
    }

    /** @param list<mixed> $tokens */
    private static function lineForIndex(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; --$i) {
            if (\is_array($tokens[$i]) && isset($tokens[$i][2])) {
                return (int) $tokens[$i][2];
            }
        }

        return 0;
    }

    private static function resolveEmitFrame(VMContext $context, string $filename): Frame
    {
        $vm = VM::running();
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null !== $frame) {
                return $frame;
            }
            $frames = $context->runStackFrames();
            if ([] !== $frames) {
                return $frames[0];
            }
        }

        $block = new Block(null);
        $frame = new Frame(null, $block, null);
        $frame->vmContext = $context;
        $frame->scriptPath = $filename;

        return $frame;
    }
}
