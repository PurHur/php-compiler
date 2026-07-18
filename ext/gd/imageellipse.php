<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imageellipse() — stroke ellipse on GdImage (php-src ext/gd/gd.c; #20438). */
final class imageellipse extends Internal
{
    public function __construct()
    {
        parent::__construct('imageellipse');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageellipse() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageellipse', 1);
        $cx = VmGd::coerceIntArg($frame->calledArgs[1], 'imageellipse', 2, 'center_x');
        $cy = VmGd::coerceIntArg($frame->calledArgs[2], 'imageellipse', 3, 'center_y');
        $w = VmGd::coerceIntArg($frame->calledArgs[3], 'imageellipse', 4, 'width');
        $h = VmGd::coerceIntArg($frame->calledArgs[4], 'imageellipse', 5, 'height');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imageellipse', 6, 'color');
        $frame->returnVar->bool(VmGd::ellipse($image, $cx, $cy, $w, $h, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageellipse() is VM-only in this compiler build (#20438)');
    }
}
