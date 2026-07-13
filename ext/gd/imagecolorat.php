<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorat() — truecolor pixel value at (x,y) (php-src ext/gd/gd.c; #6217). */
final class imagecolorat extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorat');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorat() expects exactly 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorat', 1);
        $x = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorat', 2, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecolorat', 3, 'y');
        $color = VmGd::colorAt($frame, $image, $x, $y);
        if (false === $color) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($color);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorat() is VM-only in this compiler build (#6217)');
    }
}
