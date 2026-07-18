<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecharup() — vertical built-in font character (php-src ext/gd/gd.c; #20460). */
final class imagecharup extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecharup');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecharup() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecharup', 1);
        $font = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecharup', 2, 'font');
        $x = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecharup', 3, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecharup', 4, 'y');
        $char = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imagecharup', 4, 'char');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecharup', 6, 'color');
        $frame->returnVar->bool(VmGd::charUp($image, $font, $x, $y, $char, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecharup() is VM-only in this compiler build (#20460)');
    }
}
