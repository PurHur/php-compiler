<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * print_r() — human-readable debug output (ext/standard/var.c parity, #3133).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(print_r)
 */
final class print_r extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('print_r() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('print_r() requires an active VM');
        }
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('print_r() expects 1 or 2 arguments');
        }
        $return = false;
        if (2 === $argc) {
            $return = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $out = VmPrintR::formatVariable($vm, $frame->calledArgs[0]->resolveIndirect(), 0, $frame);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($out);

            return;
        }
        if ('' !== $out) {
            OutputBuffer::append($out);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitPrintR::invoke($context, ...$args);
    }
}
