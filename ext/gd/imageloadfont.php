<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageloadfont() — load .gdf bitmap font (php-src ext/gd/gd.c; #20486).
 */
final class imageloadfont extends Internal
{
    public function __construct()
    {
        parent::__construct('imageloadfont');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageloadfont() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imageloadfont', 1, 'filename');
        $font = VmGd::loadFont($frame, $filename);
        if (false === $font) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($font);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageloadfont() is VM-only in this compiler build (#20486)');
    }
}
