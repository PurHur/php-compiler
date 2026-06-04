<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Builtin execute helpers — Zend runs the callee even when the return is discarded.
 *
 * php-src: Zend/zend_execute.c (ZEND_CALL / zend_call_function).
 *
 * @see https://github.com/PurHur/php-compiler/issues/5896
 * @see https://github.com/PurHur/php-compiler/issues/5900
 */
final class BuiltinExecute
{
    /**
     * Run validation and side effects; only skip writing the VM return slot when absent.
     *
     * @param callable(Frame): void $impl
     */
    public static function run(Frame $frame, callable $impl): void
    {
        $impl($frame);
    }

    /**
     * Write the builtin result only when the caller uses the return value (#5896).
     *
     * @param callable(Variable): void $writer
     */
    public static function writeReturn(Frame $frame, callable $writer): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $writer($frame->returnVar);
    }
}
