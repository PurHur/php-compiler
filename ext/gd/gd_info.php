<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gd_info() — GD capability assoc array (php-src ext/gd/gd.c; #20471). */
final class gd_info extends Internal
{
    public function __construct()
    {
        parent::__construct('gd_info');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('gd_info() expects exactly 0 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmGd::gdInfoToHashTable());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gd_info() is VM-only in this compiler build (#20471)');
    }
}
