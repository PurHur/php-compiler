<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagedashedline() — dashed Bresenham line on GdImage (php-src ext/gd/gd.c; #20457). */
final class imagedashedline extends Internal
{
    public function __construct()
    {
        parent::__construct('imagedashedline');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagedashedline() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagedashedline', 1);
        $x1 = VmGd::coerceIntArg($frame->calledArgs[1], 'imagedashedline', 2, 'x1');
        $y1 = VmGd::coerceIntArg($frame->calledArgs[2], 'imagedashedline', 3, 'y1');
        $x2 = VmGd::coerceIntArg($frame->calledArgs[3], 'imagedashedline', 4, 'x2');
        $y2 = VmGd::coerceIntArg($frame->calledArgs[4], 'imagedashedline', 5, 'y2');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagedashedline', 6, 'color');
        $frame->returnVar->bool(VmGd::dashedLine($image, $x1, $y1, $x2, $y2, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagedashedline() is VM-only in this compiler build (#20457)');
    }
}
