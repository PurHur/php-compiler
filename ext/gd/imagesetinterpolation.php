<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagesetinterpolation() — per-image gdInterpolationMethod (php-src ext/gd/gd.c; #20416).
 */
final class imagesetinterpolation extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesetinterpolation');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('imagesetinterpolation() expects 1 to 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesetinterpolation', 1);
        $method = $argc >= 2
            ? VmGd::coerceIntArg($frame->calledArgs[1], 'imagesetinterpolation', 2, 'method')
            : GdConstants::REGISTERED['IMG_BILINEAR_FIXED'];
        $frame->returnVar->bool(VmGd::setInterpolation($image, $method));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesetinterpolation() is VM-only in this compiler build (#20416)');
    }
}
