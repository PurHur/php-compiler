<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * setlocale() — libc setlocale(3) wrapper (issue #6133, #3254).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(setlocale)
 *
 * Null $category: Z_PARAM_LONG — soft E_DEPRECATED + coerce to 0; strict TypeError (#31487).
 */
final class setlocale extends Internal
{
    public function __construct()
    {
        parent::__construct('setlocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                \sprintf('setlocale() expects at least 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_LONG $category — soft-null DEP+0 outside strict_types (#31487).
        $category = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'setlocale',
            1,
            'category'
        );

        $result = VmLocale::setlocale(
            $category,
            \array_slice($frame->calledArgs, 1)
        );
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLocale::setlocale($context, ...$args);
    }
}
