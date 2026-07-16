<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagechar() — built-in bitmap font character (php-src ext/gd/gd.c; #6534). */
final class imagechar extends Internal
{
    public function __construct()
    {
        parent::__construct('imagechar');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagechar() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagechar', 1);
        $font = VmGd::coerceIntArg($frame->calledArgs[1], 'imagechar', 2, 'font');
        $x = VmGd::coerceIntArg($frame->calledArgs[2], 'imagechar', 3, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[3], 'imagechar', 4, 'y');
        $char = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imagechar', 4, 'char');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagechar', 6, 'color');
        $frame->returnVar->bool(VmGd::char($image, $font, $x, $y, $char, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagechar() is VM-only in this compiler build (#6534)');
    }
}
