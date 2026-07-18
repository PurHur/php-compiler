<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorclosestalpha() — php-src ext/gd/gd.c; #20459. */
final class imagecolorclosestalpha extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorclosestalpha');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorclosestalpha() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorclosestalpha', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorclosestalpha', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorclosestalpha', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorclosestalpha', 4, 'blue');
        $alpha = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecolorclosestalpha', 5, 'alpha');
        VmGd::requireRgbaComponent($red, 'imagecolorclosestalpha', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorclosestalpha', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorclosestalpha', 4, 'blue', 255);
        VmGd::requireRgbaComponent($alpha, 'imagecolorclosestalpha', 5, 'alpha', 127);
        $frame->returnVar->int(VmGd::colorClosestAlpha($image, $red, $green, $blue, $alpha));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorclosestalpha() is VM-only in this compiler build (#20459)');
    }
}
