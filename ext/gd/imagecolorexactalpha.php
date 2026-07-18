<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorexactalpha() — php-src ext/gd/gd.c; #20459. */
final class imagecolorexactalpha extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorexactalpha');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorexactalpha() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorexactalpha', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorexactalpha', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorexactalpha', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorexactalpha', 4, 'blue');
        $alpha = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecolorexactalpha', 5, 'alpha');
        VmGd::requireRgbaComponent($red, 'imagecolorexactalpha', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorexactalpha', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorexactalpha', 4, 'blue', 255);
        VmGd::requireRgbaComponent($alpha, 'imagecolorexactalpha', 5, 'alpha', 127);
        $frame->returnVar->int(VmGd::colorExactAlpha($image, $red, $green, $blue, $alpha));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorexactalpha() is VM-only in this compiler build (#20459)');
    }
}
