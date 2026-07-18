<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagefilledellipse() — filled ellipse on GdImage (php-src ext/gd/gd.c; #20438). */
final class imagefilledellipse extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilledellipse');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagefilledellipse() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilledellipse', 1);
        $cx = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefilledellipse', 2, 'center_x');
        $cy = VmGd::coerceIntArg($frame->calledArgs[2], 'imagefilledellipse', 3, 'center_y');
        $w = VmGd::coerceIntArg($frame->calledArgs[3], 'imagefilledellipse', 4, 'width');
        $h = VmGd::coerceIntArg($frame->calledArgs[4], 'imagefilledellipse', 5, 'height');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagefilledellipse', 6, 'color');
        if ($w < 0) {
            throw new \ValueError(\sprintf(
                'imagefilledellipse(): Argument #4 ($width) must be between 0 and %d',
                PHP_INT_MAX > 2147483647 ? 2147483647 : PHP_INT_MAX
            ));
        }
        $frame->returnVar->bool(VmGd::filledEllipse($image, $cx, $cy, $w, $h, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilledellipse() is VM-only in this compiler build (#20438)');
    }
}
