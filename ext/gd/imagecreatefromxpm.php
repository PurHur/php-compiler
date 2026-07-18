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
 * imagecreatefromxpm() — registered but unsupported without libXpm (php-src HAVE_GD_XPM; #20472).
 */
final class imagecreatefromxpm extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreatefromxpm');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreatefromxpm() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Still coerce/filename-check like php-src before soft-fail.
        VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imagecreatefromxpm', 1, 'filename');
        $frame->returnVar->bool(VmGd::createFromXpmUnsupported($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreatefromxpm() is VM-only in this compiler build (#20472)');
    }
}
