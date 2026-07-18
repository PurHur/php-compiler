<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageftbbox() — FreeType string bounding box primary (php-src ext/gd/gd.stub.php; #20496).
 * imagettfbbox() is the @alias of this entry point.
 */
final class imageftbbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imageftbbox');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'imageftbbox() expects at least 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $size = VmGd::coerceFloatArg($frame->calledArgs[0], 'imageftbbox', 1, 'size');
        $angle = VmGd::coerceFloatArg($frame->calledArgs[1], 'imageftbbox', 2, 'angle');
        $font = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imageftbbox', 3, 'font_filename');
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imageftbbox', 4, 'string');
        $brect = VmGd::ttfBBox($frame, $size, $angle, $font, $string);
        if (false === $brect) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmGd::brectToHashTable($brect));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageftbbox() is VM-only in this compiler build (#20496)');
    }
}
