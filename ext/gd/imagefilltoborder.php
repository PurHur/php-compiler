<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagefilltoborder() — flood fill until border color (php-src ext/gd/gd.c; #20439).
 */
final class imagefilltoborder extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilltoborder');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagefilltoborder() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilltoborder', 1);
        $x = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefilltoborder', 2, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[2], 'imagefilltoborder', 3, 'y');
        $border = VmGd::coerceIntArg($frame->calledArgs[3], 'imagefilltoborder', 4, 'border_color');
        $color = VmGd::coerceIntArg($frame->calledArgs[4], 'imagefilltoborder', 5, 'color');
        $frame->returnVar->bool(VmGd::fillToBorder($image, $x, $y, $border, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilltoborder() is VM-only in this compiler build (#20439)');
    }
}
