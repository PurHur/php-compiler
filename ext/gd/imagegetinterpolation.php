<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagegetinterpolation() — read im->interpolation_id (php-src ext/gd/gd.c; #20416).
 */
final class imagegetinterpolation extends Internal
{
    public function __construct()
    {
        parent::__construct('imagegetinterpolation');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagegetinterpolation() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagegetinterpolation', 1);
        $frame->returnVar->int(VmGd::getInterpolation($image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagegetinterpolation() is VM-only in this compiler build (#20416)');
    }
}
