<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecropauto() — auto-trim raster borders (php-src ext/gd/gd.c; #6380). */
final class imagecropauto extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecropauto');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('imagecropauto() expects 1 to 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecropauto', 1);
        $mode = $argc >= 2 ? VmGd::coerceIntArg($frame->calledArgs[1], 'imagecropauto', 2, 'mode') : GdConstants::REGISTERED['IMG_CROP_DEFAULT'];
        $threshold = $argc >= 3 ? VmGd::coerceFloatArg($frame->calledArgs[2], 'imagecropauto', 3, 'threshold') : 0.5;
        $color = $argc >= 4 ? VmGd::coerceIntArg($frame->calledArgs[3], 'imagecropauto', 4, 'color') : -1;
        $cropped = VmGd::cropAuto($frame, $image, $mode, $threshold, $color);
        if (false === $cropped) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($cropped);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecropauto() is VM-only in this compiler build (#6380)');
    }
}
