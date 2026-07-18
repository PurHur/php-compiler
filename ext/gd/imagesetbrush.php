<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagesetbrush() — brush image for IMG_COLOR_BRUSHED strokes (php-src ext/gd/gd.c; #20439).
 */
final class imagesetbrush extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesetbrush');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesetbrush() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesetbrush', 1);
        $brush = VmGd::requireGdImage($frame->calledArgs[1], 'imagesetbrush', 2);
        $frame->returnVar->bool(VmGd::setBrush($image, $brush));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesetbrush() is VM-only in this compiler build (#20439)');
    }
}
