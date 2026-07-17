<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagettfbbox() — FreeType string bounding box (php-src ext/gd/gd.c; #6532). */
final class imagettfbbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imagettfbbox');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'imagettfbbox() expects at least 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $size = VmGd::coerceFloatArg($frame->calledArgs[0], 'imagettfbbox', 1, 'size');
        $angle = VmGd::coerceFloatArg($frame->calledArgs[1], 'imagettfbbox', 2, 'angle');
        $font = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imagettfbbox', 3, 'font_filename');
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imagettfbbox', 4, 'string');
        $brect = VmGd::ttfBBox($frame, $size, $angle, $font, $string);
        if (false === $brect) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmGd::brectToHashTable($brect));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagettfbbox() is VM-only in this compiler build (#6532)');
    }
}
