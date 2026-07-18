<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagescale() — scale to a new GdImage (php-src ext/gd/gd.c; #20405). */
final class imagescale extends Internal
{
    public function __construct()
    {
        parent::__construct('imagescale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('imagescale() expects 2 to 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagescale', 1);
        $width = VmGd::coerceIntArg($frame->calledArgs[1], 'imagescale', 2, 'width');
        $height = $argc >= 3 ? VmGd::coerceIntArg($frame->calledArgs[2], 'imagescale', 3, 'height') : -1;
        $mode = $argc >= 4
            ? VmGd::coerceIntArg($frame->calledArgs[3], 'imagescale', 4, 'mode')
            : GdConstants::REGISTERED['IMG_BILINEAR_FIXED'];
        $scaled = VmGd::scale($frame, $image, $width, $height, $mode);
        if (false === $scaled) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($scaled);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagescale() is VM-only in this compiler build (#20405)');
    }
}
