<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorallocate() — allocate truecolor value (php-src ext/gd/gd.c; #3496). */
final class imagecolorallocate extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorallocate');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorallocate() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorallocate', 1);
        $red = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorallocate', 2, 'red');
        $green = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorallocate', 3, 'green');
        $blue = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecolorallocate', 4, 'blue');
        $color = VmGd::colorAllocate($image, $red, $green, $blue);
        if (false === $color) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($color);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorallocate() is VM-only in this compiler build (#3496)');
    }
}
