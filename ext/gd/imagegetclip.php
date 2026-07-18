<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagegetclip() — return [x1,y1,x2,y2] clip rect (php-src ext/gd/gd.c; #20460). */
final class imagegetclip extends Internal
{
    public function __construct()
    {
        parent::__construct('imagegetclip');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagegetclip() expects exactly 1 argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagegetclip', 1);
        $frame->returnVar->array(VmGd::getClipToHashTable($image));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagegetclip() is VM-only in this compiler build (#20460)');
    }
}
