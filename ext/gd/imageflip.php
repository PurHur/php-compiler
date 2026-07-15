<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imageflip() — flip raster in place (php-src ext/gd/gd.c; #6380). */
final class imageflip extends Internal
{
    public function __construct()
    {
        parent::__construct('imageflip');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageflip() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageflip', 1);
        $mode = VmGd::coerceIntArg($frame->calledArgs[1], 'imageflip', 2, 'mode');
        $frame->returnVar->bool(VmGd::flip($image, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageflip() is VM-only in this compiler build (#6380)');
    }
}
