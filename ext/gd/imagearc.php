<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagearc() — outline elliptical arc (php-src ext/gd/gd.c; #20437). */
final class imagearc extends Internal
{
    public function __construct()
    {
        parent::__construct('imagearc');
    }

    public function execute(Frame $frame): void
    {
        if (8 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagearc() expects exactly 8 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagearc', 1);
        $cx = VmGd::coerceIntArg($frame->calledArgs[1], 'imagearc', 2, 'center_x');
        $cy = VmGd::coerceIntArg($frame->calledArgs[2], 'imagearc', 3, 'center_y');
        $w = VmGd::coerceIntArg($frame->calledArgs[3], 'imagearc', 4, 'width');
        $h = VmGd::coerceIntArg($frame->calledArgs[4], 'imagearc', 5, 'height');
        $s = VmGd::coerceIntArg($frame->calledArgs[5], 'imagearc', 6, 'start_angle');
        $e = VmGd::coerceIntArg($frame->calledArgs[6], 'imagearc', 7, 'end_angle');
        $color = VmGd::coerceIntArg($frame->calledArgs[7], 'imagearc', 8, 'color');
        $frame->returnVar->bool(VmGd::arc($image, $cx, $cy, $w, $h, $s, $e, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagearc() is VM-only in this compiler build (#20437)');
    }
}
