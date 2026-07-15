<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagesx() — GdImage width (php-src ext/gd/gd.c; #6217). */
final class imagesx extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesx');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesx() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesx', 1);
        $width = VmGd::getWidth($image);
        if (false === $width) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($width);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesx() is VM-only in this compiler build (#6217)');
    }
}
