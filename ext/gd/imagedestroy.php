<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagedestroy() — release GdImage state (php-src ext/gd/gd.c; #3496). */
final class imagedestroy extends Internal
{
    public function __construct()
    {
        parent::__construct('imagedestroy');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagedestroy() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagedestroy', 1);
        $frame->returnVar->bool(VmGd::destroy($image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagedestroy() is VM-only in this compiler build (#3496)');
    }
}
