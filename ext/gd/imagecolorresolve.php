<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorresolve() — php-src ext/gd/gd.c; #20459. */
final class imagecolorresolve extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorresolve');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorresolve() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorresolve', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorresolve', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorresolve', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorresolve', 4, 'blue');
        VmGd::requireRgbaComponent($red, 'imagecolorresolve', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorresolve', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorresolve', 4, 'blue', 255);
        $frame->returnVar->int(VmGd::colorResolve($image, $red, $green, $blue));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorresolve() is VM-only in this compiler build (#20459)');
    }
}
