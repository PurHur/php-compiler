<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagecreatefromstring() — decode PNG bytes to GdImage (php-src ext/gd/gd.c; #6215).
 */
final class imagecreatefromstring extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecreatefromstring');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecreatefromstring() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $data = VmGd::coerceImageString($frame, $frame->calledArgs[0], 'imagecreatefromstring');
        $image = VmGd::createFromString($frame, $data);
        if (false === $image) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($image);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecreatefromstring() is VM-only in this compiler build (#6215)');
    }
}
