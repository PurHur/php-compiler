<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageopenpolygon() — stroke without closing edge (php-src ext/gd/gd.c; #20431).
 */
final class imageopenpolygon extends Internal
{
    public function __construct()
    {
        parent::__construct('imageopenpolygon');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageopenpolygon', 1);
        [$points, $color] = VmGd::parsePolygonArgs($frame, 'imageopenpolygon');
        $frame->returnVar->bool(VmGd::strokePolygon($image, $points, $color, VmGd::POLYGON_OPEN));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageopenpolygon() is VM-only in this compiler build (#20431)');
    }
}
