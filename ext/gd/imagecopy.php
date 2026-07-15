<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecopy() — blit rectangular region between GdImage resources (php-src ext/gd/gd.c; #6292). */
final class imagecopy extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecopy');
    }

    public function execute(Frame $frame): void
    {
        if (8 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecopy() expects exactly 8 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dst = VmGd::requireGdImage($frame->calledArgs[0], 'imagecopy', 1);
        $src = VmGd::requireGdImage($frame->calledArgs[1], 'imagecopy', 2);
        $dstX = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecopy', 3, 'dst_x');
        $dstY = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecopy', 4, 'dst_y');
        $srcX = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecopy', 5, 'src_x');
        $srcY = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecopy', 6, 'src_y');
        $srcW = VmGd::coerceIntArg($frame->calledArgs[6], 'imagecopy', 7, 'src_w');
        $srcH = VmGd::coerceIntArg($frame->calledArgs[7], 'imagecopy', 8, 'src_h');
        $frame->returnVar->bool(VmGd::copy($dst, $src, $dstX, $dstY, $srcX, $srcY, $srcW, $srcH));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecopy() is VM-only in this compiler build (#6292)');
    }
}
