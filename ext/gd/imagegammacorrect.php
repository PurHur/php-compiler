<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagegammacorrect() — gamma remap pixels/palette (php-src ext/gd/gd.c; #20460). */
final class imagegammacorrect extends Internal
{
    public function __construct()
    {
        parent::__construct('imagegammacorrect');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagegammacorrect() expects exactly 3 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagegammacorrect', 1);
        $input = VmGd::coerceFloatArg($frame->calledArgs[1], 'imagegammacorrect', 2, 'inputgamma');
        $output = VmGd::coerceFloatArg($frame->calledArgs[2], 'imagegammacorrect', 3, 'outputgamma');
        $frame->returnVar->bool(VmGd::gammaCorrect($image, $input, $output));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagegammacorrect() is VM-only in this compiler build (#20460)');
    }
}
