<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorresolvealpha() — php-src ext/gd/gd.c; #20459. */
final class imagecolorresolvealpha extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorresolvealpha');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorresolvealpha() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorresolvealpha', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorresolvealpha', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorresolvealpha', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorresolvealpha', 4, 'blue');
        $alpha = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecolorresolvealpha', 5, 'alpha');
        VmGd::requireRgbaComponent($red, 'imagecolorresolvealpha', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorresolvealpha', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorresolvealpha', 4, 'blue', 255);
        VmGd::requireRgbaComponent($alpha, 'imagecolorresolvealpha', 5, 'alpha', 127);
        $frame->returnVar->int(VmGd::colorResolveAlpha($image, $red, $green, $blue, $alpha));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorresolvealpha() is VM-only in this compiler build (#20459)');
    }
}
