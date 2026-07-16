<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imageline() — Bresenham line on truecolor GdImage (php-src ext/gd/gd.c; #6534). */
final class imageline extends Internal
{
    public function __construct()
    {
        parent::__construct('imageline');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageline() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageline', 1);
        $x1 = VmGd::coerceIntArg($frame->calledArgs[1], 'imageline', 2, 'x1');
        $y1 = VmGd::coerceIntArg($frame->calledArgs[2], 'imageline', 3, 'y1');
        $x2 = VmGd::coerceIntArg($frame->calledArgs[3], 'imageline', 4, 'x2');
        $y2 = VmGd::coerceIntArg($frame->calledArgs[4], 'imageline', 5, 'y2');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imageline', 6, 'color');
        $frame->returnVar->bool(VmGd::line($image, $x1, $y1, $x2, $y2, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageline() is VM-only in this compiler build (#6534)');
    }
}
