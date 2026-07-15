<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecreatetruecolor() — allocate truecolor canvas (php-src ext/gd/gd.c; #3496).
 */
final class imagecreatetruecolor extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreatetruecolor');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreatetruecolor() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $width = VmGd::coerceIntArg($frame->calledArgs[0], 'imagecreatetruecolor', 1, 'width');
        $height = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecreatetruecolor', 2, 'height');
        $image = VmGd::createTruecolorImage($frame, $width, $height);
        if (false === $image) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($image);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreatetruecolor() is VM-only in this compiler build (#3496)');
    }
}
