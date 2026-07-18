<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imageaffinematrixconcat() — multiply two affine matrices (php-src ext/gd/gd.c; #20441).
 */
final class imageaffinematrixconcat extends Internal
{
    public function __construct()
    {
        parent::__construct('imageaffinematrixconcat');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imageaffinematrixconcat() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $m1 = VmGd::coerceAffineMatrix($frame->calledArgs[0], 'imageaffinematrixconcat', 1, 'matrix1');
        $m2 = VmGd::coerceAffineMatrix($frame->calledArgs[1], 'imageaffinematrixconcat', 2, 'matrix2');
        $frame->returnVar->array(VmGd::affineMatrixToHashTable(VmGd::concatAffineMatrices($m1, $m2)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageaffinematrixconcat() is VM-only in this compiler build (#20441)');
    }
}
