<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageistruecolor() — GdImage truecolor flag (php-src ext/gd/gd.c; #20415).
 */
final class imageistruecolor extends Internal
{
    public function __construct()
    {
        parent::__construct('imageistruecolor');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageistruecolor() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageistruecolor', 1);
        $frame->returnVar->bool(VmGd::isTruecolor($image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageistruecolor() is VM-only in this compiler build (#20415)');
    }
}
