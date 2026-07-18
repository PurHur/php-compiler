<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imagecolorsforindex() — palette/truecolor RGBA components (php-src ext/gd/gd.c; #20440). */
final class imagecolorsforindex extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolorsforindex');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagecolorsforindex() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolorsforindex', 1);
        $color = VmGd::coerceIntArg($frame->calledArgs[1], 'imagecolorsforindex', 2, 'color');
        $frame->returnVar->array(VmGd::colorsForIndexToHashTable(VmGd::colorsForIndex($image, $color)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolorsforindex() is VM-only in this compiler build (#20440)');
    }
}
