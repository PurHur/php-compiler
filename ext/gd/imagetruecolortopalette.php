<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagetruecolortopalette() — quantize truecolor → palette (php-src ext/gd/gd.c; #20415).
 */
final class imagetruecolortopalette extends Internal
{
    public function __construct()
    {
        parent::__construct('imagetruecolortopalette');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagetruecolortopalette() expects exactly 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagetruecolortopalette', 1);
        $dither = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'imagetruecolortopalette', 2, 'dither');
        $numColors = VmGd::coerceIntArg($frame->calledArgs[2], 'imagetruecolortopalette', 3, 'num_colors');
        $frame->returnVar->bool(VmGd::trueColorToPalette($frame, $image, $dither, $numColors));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagetruecolortopalette() is VM-only in this compiler build (#20415)');
    }
}
