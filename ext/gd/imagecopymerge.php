<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecopymerge() — alpha-merge blit between GdImage resources (php-src ext/gd/gd.c; #6292). */
final class imagecopymerge extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecopymerge');
    }

    public function execute(Frame $frame): void
    {
        if (9 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecopymerge() expects exactly 9 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $dst = VmGd::requireGdImage($frame->calledArgs[0], 'imagecopymerge', 1);
        $src = VmGd::requireGdImage($frame->calledArgs[1], 'imagecopymerge', 2);
        $dstX = VmGd::coerceIntArg($frame->calledArgs[2], 'imagecopymerge', 3, 'dst_x');
        $dstY = VmGd::coerceIntArg($frame->calledArgs[3], 'imagecopymerge', 4, 'dst_y');
        $srcX = VmGd::coerceIntArg($frame->calledArgs[4], 'imagecopymerge', 5, 'src_x');
        $srcY = VmGd::coerceIntArg($frame->calledArgs[5], 'imagecopymerge', 6, 'src_y');
        $srcW = VmGd::coerceIntArg($frame->calledArgs[6], 'imagecopymerge', 7, 'src_w');
        $srcH = VmGd::coerceIntArg($frame->calledArgs[7], 'imagecopymerge', 8, 'src_h');
        $pct = VmGd::coerceIntArg($frame->calledArgs[8], 'imagecopymerge', 9, 'pct');
        $frame->returnVar->bool(VmGd::copyMerge($dst, $src, $dstX, $dstY, $srcX, $srcY, $srcW, $srcH, $pct));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecopymerge() is VM-only in this compiler build (#6292)');
    }
}
