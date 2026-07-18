<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagepolygon() — closed stroke polygon (php-src ext/gd/gd.c; #20431).
 */
final class imagepolygon extends Internal
{
    public function __construct()
    {
        parent::__construct('imagepolygon');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagepolygon', 1);
        [$points, $color] = VmGd::parsePolygonArgs($frame, 'imagepolygon');
        $frame->returnVar->bool(VmGd::strokePolygon($image, $points, $color, VmGd::POLYGON_CLOSED));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagepolygon() is VM-only in this compiler build (#20431)');
    }
}
