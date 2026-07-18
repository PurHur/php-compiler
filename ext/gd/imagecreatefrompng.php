<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecreatefrompng() — decode PNG file to GdImage (php-src ext/gd/gd.c; #20458).
 */
final class imagecreatefrompng extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreatefrompng');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreatefrompng() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imagecreatefrompng', 1, 'filename');
        $data = VmFs::fileGetContents($path, false, null, 0, null, $frame->vmContext);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $image = VmGd::createFromPngBytes($frame, $data);
        if (false === $image) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($image);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreatefrompng() is VM-only in this compiler build (#20458)');
    }
}
