<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagesetthickness() — set stroke width for lines/arcs (php-src ext/gd/gd.c; #20406).
 */
final class imagesetthickness extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesetthickness');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesetthickness() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesetthickness', 1);
        $thickness = VmGd::coerceIntArg($frame->calledArgs[1], 'imagesetthickness', 2, 'thickness');
        $frame->returnVar->bool(VmGd::setThickness($image, $thickness));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesetthickness() is VM-only in this compiler build (#20406)');
    }
}
