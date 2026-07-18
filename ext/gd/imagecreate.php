<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecreate() — allocate palette canvas (php-src ext/gd/gd.c; #7407, #20415).
 */
final class imagecreate extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreate');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreate() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $width = VmGd::coerceIntArg($frame->calledArgs[0], 'imagecreate', 1, 'width');
        $height = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecreate', 2, 'height');
        $image = VmGd::createPaletteImage($frame, $width, $height);
        if (false === $image) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($image);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreate() is VM-only in this compiler build (#20415)');
    }
}
