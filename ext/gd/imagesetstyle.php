<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagesetstyle() — style colors for IMG_COLOR_STYLED strokes (php-src ext/gd/gd.c; #20439).
 */
final class imagesetstyle extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesetstyle');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesetstyle() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesetstyle', 1);
        $style = VmGd::coerceStyleArray($frame->calledArgs[1], 'imagesetstyle', 2);
        $frame->returnVar->bool(VmGd::setStyle($image, $style));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesetstyle() is VM-only in this compiler build (#20439)');
    }
}
