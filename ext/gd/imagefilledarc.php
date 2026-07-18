<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagefilledarc() — filled/outline arc styles (php-src ext/gd/gd.c; #20437). */
final class imagefilledarc extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilledarc');
    }

    public function execute(Frame $frame): void
    {
        if (9 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagefilledarc() expects exactly 9 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilledarc', 1);
        $cx = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefilledarc', 2, 'center_x');
        $cy = VmGd::coerceIntArg($frame->calledArgs[2], 'imagefilledarc', 3, 'center_y');
        $w = VmGd::coerceIntArg($frame->calledArgs[3], 'imagefilledarc', 4, 'width');
        $h = VmGd::coerceIntArg($frame->calledArgs[4], 'imagefilledarc', 5, 'height');
        $s = VmGd::coerceIntArg($frame->calledArgs[5], 'imagefilledarc', 6, 'start_angle');
        $e = VmGd::coerceIntArg($frame->calledArgs[6], 'imagefilledarc', 7, 'end_angle');
        $color = VmGd::coerceIntArg($frame->calledArgs[7], 'imagefilledarc', 8, 'color');
        $style = VmGd::coerceIntArg($frame->calledArgs[8], 'imagefilledarc', 9, 'style');
        $frame->returnVar->bool(VmGd::filledArc($image, $cx, $cy, $w, $h, $s, $e, $color, $style));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilledarc() is VM-only in this compiler build (#20437)');
    }
}
