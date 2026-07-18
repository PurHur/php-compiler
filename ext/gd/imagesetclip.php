<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagesetclip() — set drawing clip rectangle (php-src ext/gd/gd.c; #20460). */
final class imagesetclip extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesetclip');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesetclip() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesetclip', 1);
        $x1 = VmGd::coerceIntArg($frame->calledArgs[1], 'imagesetclip', 2, 'x1');
        $y1 = VmGd::coerceIntArg($frame->calledArgs[2], 'imagesetclip', 3, 'y1');
        $x2 = VmGd::coerceIntArg($frame->calledArgs[3], 'imagesetclip', 4, 'x2');
        $y2 = VmGd::coerceIntArg($frame->calledArgs[4], 'imagesetclip', 5, 'y2');
        $frame->returnVar->bool(VmGd::setClip($image, $x1, $y1, $x2, $y2));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesetclip() is VM-only in this compiler build (#20460)');
    }
}
