<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecopyresampled() — resampled scale blit between GdImage resources (php-src ext/gd/gd.c; #6292). */
final class imagecopyresampled extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecopyresampled');
    }

    public function execute(Frame $frame): void
    {
        if (10 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecopyresampled() expects exactly 10 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dst = VmGd::requireGdImage($frame->calledArgs[0], 'imagecopyresampled', 1);
        $src = VmGd::requireGdImage($frame->calledArgs[1], 'imagecopyresampled', 2);
        $dstX = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecopyresampled', 3, 'dst_x');
        $dstY = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecopyresampled', 4, 'dst_y');
        $srcX = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecopyresampled', 5, 'src_x');
        $srcY = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecopyresampled', 6, 'src_y');
        $dstW = VmGd::coerceIntArg($frame->calledArgs[6], 'imagecopyresampled', 7, 'dst_w');
        $dstH = VmGd::coerceIntArg($frame->calledArgs[7], 'imagecopyresampled', 8, 'dst_h');
        $srcW = VmGd::coerceIntArg($frame->calledArgs[8], 'imagecopyresampled', 9, 'src_w');
        $srcH = VmGd::coerceIntArg($frame->calledArgs[9], 'imagecopyresampled', 10, 'src_h');
        $frame->returnVar->bool(VmGd::copyResampled(
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
        throw new \LogicException('imagecopyresampled() is VM-only in this compiler build (#6292)');
    }
}
