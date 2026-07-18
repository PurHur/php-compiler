<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** imagecolortransparent() — get/set transparent color (php-src ext/gd/gd.c; #20459). */
final class imagecolortransparent extends Internal
{
    public function __construct()
    {
        parent::__construct('imagecolortransparent');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('imagecolortransparent() expects 1 to 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagecolortransparent', 1);
        $color = null;
        if ($argc >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $color = VmGd::coerceIntArg($arg, 'imagecolortransparent', 2, 'color');
            }
        }
        $frame->returnVar->int(VmGd::colorTransparent($image, $color));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagecolortransparent() is VM-only in this compiler build (#20459)');
    }
}
