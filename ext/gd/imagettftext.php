<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagettftext() — FreeType string draw (php-src ext/gd/gd.c; #6532). */
final class imagettftext extends Internal
{
    public function __construct()
    {
        parent::__construct('imagettftext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 8 || $argc > 9) {
            throw new \ArgumentCountError(
                'imagettftext() expects at least 8 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagettftext', 1);
        $size = VmGd::coerceFloatArg($frame->calledArgs[1], 'imagettftext', 2, 'size');
        $angle = VmGd::coerceFloatArg($frame->calledArgs[2], 'imagettftext', 3, 'angle');
        $x = VmGd::coerceIntArg($frame->calledArgs[3], 'imagettftext', 4, 'x');
        $y = VmGd::coerceIntArg($frame->calledArgs[4], 'imagettftext', 5, 'y');
        $color = VmGd::coerceIntArg($frame->calledArgs[5], 'imagettftext', 6, 'color');
        $font = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'imagettftext', 7, 'font_filename');
        $text = VmString::coerceStringBuiltinArg($frame->calledArgs[7], 'imagettftext', 8, 'text');
        $brect = VmGd::ttfText($frame, $image, $size, $angle, $x, $y, $color, $font, $text);
        if (false === $brect) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmGd::brectToHashTable($brect));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagettftext() is VM-only in this compiler build (#6532)');
    }
}
