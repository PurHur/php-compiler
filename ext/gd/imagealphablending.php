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
 * imagealphablending() — toggle truecolor alpha blend vs replace (php-src ext/gd/gd.c; #6535).
 */
final class imagealphablending extends Internal
{
    public function __construct()
    {
        parent::__construct('imagealphablending');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagealphablending() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagealphablending', 1);
        $enable = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'imagealphablending', 2, 'enable');
        $frame->returnVar->bool(VmGd::setAlphaBlending($image, $enable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagealphablending() is VM-only in this compiler build (#6535)');
    }
}
