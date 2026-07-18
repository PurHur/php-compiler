<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorclosesthwb() — nearest palette index by HWB distance (php-src ext/gd/gd.c; #20473). */
final class imagecolorclosesthwb extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorclosesthwb');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorclosesthwb() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorclosesthwb', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorclosesthwb', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorclosesthwb', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorclosesthwb', 4, 'blue');
        VmGd::requireRgbaComponent($red, 'imagecolorclosesthwb', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorclosesthwb', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorclosesthwb', 4, 'blue', 255);
        $frame->returnVar->int(VmGd::colorClosestHwb($image, $red, $green, $blue));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorclosesthwb() is VM-only in this compiler build (#20473)');
    }
}
