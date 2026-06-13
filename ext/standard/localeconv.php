<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * localeconv() — libc localeconv(3) wrapper (issue #6133, #3254).
 *
 * php-src: ext/standard/locale.c — PHP_FUNCTION(localeconv)
 */
final class localeconv extends Internal
{
    public function __construct()
    {
        parent::__construct('localeconv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                \sprintf('localeconv() expects exactly 0 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmLocale::localeconv());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLocale::localeconv($context, ...$args);
    }
}
