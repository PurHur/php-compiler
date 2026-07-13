<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagefill() — flood fill on raster image (php-src ext/gd/gd.c; #3496). */
final class imagefill extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefill');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagefill() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefill', 1);
        $x = VmGd::coerceIntArg($frame->calledArgs[1], 'imagefill', 2, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[2], 'imagefill', 3, 'y');
        $color = VmGd::coerceIntArg($frame->calledArgs[3], 'imagefill', 4, 'color');
        $frame->returnVar->bool(VmGd::fill($image, $x, $y, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefill() is VM-only in this compiler build (#3496)');
    }
}
