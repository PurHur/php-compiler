<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecreatefromgd2part() — decode GD2 crop region (php-src ext/gd/gd.c; #20502). */
final class imagecreatefromgd2part extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreatefromgd2part');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreatefromgd2part() expects exactly 5 arguments in this compiler build');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imagecreatefromgd2part', 1, 'filename');
        $srcx = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'imagecreatefromgd2part', 2, 'x');
        $srcy = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'imagecreatefromgd2part', 3, 'y');
        $width = VmMath::parseIntBuiltinArg($frame->calledArgs[3], 'imagecreatefromgd2part', 4, 'width');
        $height = VmMath::parseIntBuiltinArg($frame->calledArgs[4], 'imagecreatefromgd2part', 5, 'height');
        $data = VmFs::fileGetContents($path, false, null, 0, null, $frame->vmContext);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $image = VmGd::createFromGd2PartBytes($frame, $data, $srcx, $srcy, $width, $height);
        if (false === $image) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($image);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreatefromgd2part() is VM-only in this compiler build (#20502)');
    }
}
