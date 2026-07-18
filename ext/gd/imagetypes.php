<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagetypes() — bitmask of supported image formats (php-src ext/gd/gd.c; #20471). */
final class imagetypes extends Internal
{
    public function __construct()
    {
        parent::__construct('imagetypes');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagetypes() expects exactly 0 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmGd::imageTypesMask());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagetypes() is VM-only in this compiler build (#20471)');
    }
}
