<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagerotate() — counterclockwise rotate to a new GdImage (php-src ext/gd/gd.c; #20405). */
final class imagerotate extends Internal
{
    public function __construct()
    {
        parent::__construct('imagerotate');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagerotate() expects exactly 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagerotate', 1);
        $angle = VmGd::coerceFloatArg($frame->calledArgs[1], 'imagerotate', 2, 'angle');
        $bg = VmGd::coerceIntArg($frame->calledArgs[2], 'imagerotate', 3, 'background_color');
        $rotated = VmGd::rotate($frame, $image, $angle, $bg);
        if (false === $rotated) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($rotated);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagerotate() is VM-only in this compiler build (#20405)');
    }
}
