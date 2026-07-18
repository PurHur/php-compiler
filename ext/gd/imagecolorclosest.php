<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorclosest() — nearest palette index / truecolor pack (php-src ext/gd/gd.c; #20440). */
final class imagecolorclosest extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorclosest');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorclosest() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorclosest', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorclosest', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorclosest', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorclosest', 4, 'blue');
        VmGd::requireRgbaComponent($red, 'imagecolorclosest', 2, 'red', 255);
        VmGd::requireRgbaComponent($green, 'imagecolorclosest', 3, 'green', 255);
        VmGd::requireRgbaComponent($blue, 'imagecolorclosest', 4, 'blue', 255);
        $frame->returnVar->int(VmGd::colorClosest($image, $red, $green, $blue));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorclosest() is VM-only in this compiler build (#20440)');
    }
}
