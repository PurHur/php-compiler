<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageantialias() — toggle truecolor antialiased strokes (php-src ext/gd/gd.c; #20406).
 */
final class imageantialias extends Internal
{
    public function __construct()
    {
        parent::__construct('imageantialias');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageantialias() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageantialias', 1);
        $enable = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'imageantialias', 2, 'enable');
        $frame->returnVar->bool(VmGd::setAntiAlias($image, $enable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageantialias() is VM-only in this compiler build (#20406)');
    }
}
