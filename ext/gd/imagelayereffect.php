<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagelayereffect() — set gdEffect* alphaBlendingFlag (php-src ext/gd/gd.c; #20429).
 */
final class imagelayereffect extends Internal
{
    public function __construct()
    {
        parent::__construct('imagelayereffect');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagelayereffect() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagelayereffect', 1);
        $effect = VmGd::coerceIntArg($frame->calledArgs[1], 'imagelayereffect', 2, 'effect');
        $frame->returnVar->bool(VmGd::setLayerEffect($image, $effect));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagelayereffect() is VM-only in this compiler build (#20429)');
    }
}
