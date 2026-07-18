<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageaffinematrixget() — build standard affine matrix (php-src ext/gd/gd.c; #20441).
 */
final class imageaffinematrixget extends Internal
{
    public function __construct()
    {
        parent::__construct('imageaffinematrixget');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageaffinematrixget() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = VmGd::coerceIntArg($frame->calledArgs[0], 'imageaffinematrixget', 1, 'type');
        $matrix = VmGd::affineMatrixGet($type, $frame->calledArgs[1]);
        if (null === $matrix) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmGd::affineMatrixToHashTable($matrix));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageaffinematrixget() is VM-only in this compiler build (#20441)');
    }
}
