<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * vsprintf() — format string + array of values (issue #3190, php-src ext/standard/sprintf.c).
 */
final class vsprintf extends Internal
{
    public function __construct()
    {
        parent::__construct('vsprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'vsprintf() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $format = VmString::stringBuiltinArgForFrame($frame, 0, 'vsprintf', 0, 'format');
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        $frame->returnVar->string(VmVprintf::formatString($format, $argsVar, $frame, 'vsprintf'));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVsprintf::format($context, ...$args);
    }
}
