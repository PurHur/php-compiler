<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagesy() — GdImage height (php-src ext/gd/gd.c; #6217). */
final class imagesy extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesy');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesy() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesy', 1);
        $height = VmGd::getHeight($image);
        if (false === $height) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($height);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesy() is VM-only in this compiler build (#6217)');
    }
}
