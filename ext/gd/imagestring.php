<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagestring() — built-in bitmap font string (php-src ext/gd/gd.c; #6534). */
final class imagestring extends Internal
{
    public function __construct()
    {
        parent::__construct('imagestring');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagestring() expects exactly 6 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagestring', 1);
        $font = VmGd::resolveFont($frame->calledArgs[1], 'imagestring', 2);
        $x = VmGd::coerceIntArg($frame->calledArgs[2], 'imagestring', 3, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[3], 'imagestring', 4, 'y');
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'imagestring', 4, 'string');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagestring', 6, 'color');
        $frame->returnVar->bool(VmGd::string($image, $font, $x, $y, $text, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagestring() is VM-only in this compiler build (#6534)');
    }
}
