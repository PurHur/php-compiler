<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorset() — mutate palette entry (php-src ext/gd/gd.c; #20440). */
final class imagecolorset extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \LogicException('imagecolorset() expects 5 to 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorset', 1);
        $color = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorset', 2, 'color');
        $red = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorset', 3, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorset', 4, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecolorset', 5, 'blue');
        $alpha = 0;
        if ($argc >= 6) {
            $alpha = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecolorset', 6, 'alpha');
        }
        VmGd::requireRgbaComponent($red, 'imagecolorset', 3, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorset', 4, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorset', 5, 'blue', 255);
        VmGd::requireRgbaComponent($alpha, 'imagecolorset', 6, 'alpha', 127);
        $result = VmGd::colorSet($image, $color, $red, $green, $blue, $alpha);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->null();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorset() is VM-only in this compiler build (#20440)');
    }
}
