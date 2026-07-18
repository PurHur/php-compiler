<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imageconvolution() — in-place 3×3 convolution (php-src ext/gd/gd.c; #20405). */
final class imageconvolution extends Internal
{
    public function __construct()
    {
        parent::__construct('imageconvolution');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageconvolution() expects exactly 4 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageconvolution', 1);
        $matrix = VmGd::coerceConvolutionMatrix($frame->calledArgs[1], 'imageconvolution', 2);
        $divisor = VmGd::coerceFloatArg($frame->calledArgs[2], 'imageconvolution', 3, 'divisor');
        $offset = VmGd::coerceFloatArg($frame->calledArgs[3], 'imageconvolution', 4, 'offset');
        $frame->returnVar->bool(VmGd::convolve($image, $matrix, $divisor, $offset));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageconvolution() is VM-only in this compiler build (#20405)');
    }
}
