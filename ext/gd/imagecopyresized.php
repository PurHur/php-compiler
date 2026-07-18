<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecopyresized() — nearest-neighbour scale blit (php-src ext/gd/gd.c; #20405). */
final class imagecopyresized extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecopyresized');
    }

    public function execute(Frame $frame): void
    {
        if (10 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecopyresized() expects exactly 10 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dst = VmGd::requireGdImage($frame->calledArgs[0], 'imagecopyresized', 1);
        $src = VmGd::requireGdImage($frame->calledArgs[1], 'imagecopyresized', 2);
        $dstX = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecopyresized', 3, 'dst_x');
        $dstY = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecopyresized', 4, 'dst_y');
        $srcX = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecopyresized', 5, 'src_x');
        $srcY = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecopyresized', 6, 'src_y');
        $dstW = VmGd::coerceIntArg($frame->calledArgs[6], 'imagecopyresized', 7, 'dst_w');
        $dstH = VmGd::coerceIntArg($frame->calledArgs[7], 'imagecopyresized', 8, 'dst_h');
        $srcW = VmGd::coerceIntArg($frame->calledArgs[8], 'imagecopyresized', 9, 'src_w');
        $srcH = VmGd::coerceIntArg($frame->calledArgs[9], 'imagecopyresized', 10, 'src_h');
        if ($dstW <= 0) {
            throw new \ValueError('imagecopyresized(): Argument #7 ($dst_width) must be greater than 0');
        }
        if ($dstH <= 0) {
            throw new \ValueError('imagecopyresized(): Argument #8 ($dst_height) must be greater than 0');
        }
        if ($srcW <= 0) {
            throw new \ValueError('imagecopyresized(): Argument #9 ($src_width) must be greater than 0');
        }
        if ($srcH <= 0) {
            throw new \ValueError('imagecopyresized(): Argument #10 ($src_height) must be greater than 0');
        }
        $frame->returnVar->bool(VmGd::copyResized(
            $dst,
            $src,
            $dstX,
            $dstY,
            $srcX,
            $srcY,
            $dstW,
            $dstH,
            $srcW,
            $srcH
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecopyresized() is VM-only in this compiler build (#20405)');
    }
}
