<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagestringup() — vertical built-in font string (php-src ext/gd/gd.c; #20460). */
final class imagestringup extends Internal
{
    public function __construct()
    {
        parent::__construct('imagestringup');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagestringup() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagestringup', 1);
        $font = VmGd::coerceIntArg($frame->calledArgs[1], 'imagestringup', 2, 'font');
        $x = VmGd::coerceIntArg($frame->calledArgs[2], 'imagestringup', 3, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[3], 'imagestringup', 4, 'y');
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imagestringup', 4, 'string');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagestringup', 6, 'color');
        $frame->returnVar->bool(VmGd::stringUp($image, $font, $x, $y, $text, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagestringup() is VM-only in this compiler build (#20460)');
    }
}
