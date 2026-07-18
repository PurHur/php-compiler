<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecolormatch() — rematch palette to truecolor source (php-src ext/gd/gd.c; #20486).
 */
final class imagecolormatch extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolormatch');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolormatch() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image1 = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolormatch', 1);
        $image2 = VmGd::requireGdImage($frame->calledArgs[1], 'imagecolormatch', 2);
        $frame->returnVar->bool(VmGd::colorMatch($image1, $image2));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolormatch() is VM-only in this compiler build (#20486)');
    }
}
