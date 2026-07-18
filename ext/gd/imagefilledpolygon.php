<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagefilledpolygon() — scanline-filled polygon (php-src ext/gd/gd.c; #20448).
 */
final class imagefilledpolygon extends Internal
{
    public function __construct()
    {
        parent::__construct('imagefilledpolygon');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagefilledpolygon', 1);
        [$points, $color] = VmGd::parsePolygonArgs($frame, 'imagefilledpolygon');
        $frame->returnVar->bool(VmGd::filledPolygon($image, $points, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagefilledpolygon() is VM-only in this compiler build (#20448)');
    }
}
