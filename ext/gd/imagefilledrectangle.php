<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagefilledrectangle() — filled rect on truecolor GdImage (php-src ext/gd/gd.c; #6534). */
final class imagefilledrectangle extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilledrectangle');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagefilledrectangle() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilledrectangle', 1);
        $x1 = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefilledrectangle', 2, 'x1');
        $y1 = VmGd::coerceIntArg($frame->calledArgs[2], 'imagefilledrectangle', 3, 'y1');
        $x2 = VmGd::coerceIntArg($frame->calledArgs[3], 'imagefilledrectangle', 4, 'x2');
        $y2 = VmGd::coerceIntArg($frame->calledArgs[4], 'imagefilledrectangle', 5, 'y2');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagefilledrectangle', 6, 'color');
        $frame->returnVar->bool(VmGd::filledRectangle($image, $x1, $y1, $x2, $y2, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilledrectangle() is VM-only in this compiler build (#6534)');
    }
}
