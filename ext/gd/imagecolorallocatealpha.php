<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecolorallocatealpha() — truecolor ARGB with GD alpha 0..127 (php-src ext/gd/gd.c; #6535).
 */
final class imagecolorallocatealpha extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorallocatealpha');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorallocatealpha() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorallocatealpha', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorallocatealpha', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorallocatealpha', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorallocatealpha', 4, 'blue');
        $alpha = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecolorallocatealpha', 5, 'alpha');
        $color = VmGd::colorAllocateAlpha($image, $red, $green, $blue, $alpha);
        if (false === $color) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($color);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorallocatealpha() is VM-only in this compiler build (#6535)');
    }
}
