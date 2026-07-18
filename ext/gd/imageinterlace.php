<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imageinterlace() — get/set PNG interlace flag (php-src ext/gd/gd.c; #20460).
 */
final class imageinterlace extends Internal
{
    public function __construct()
    {
        parent::__construct('imageinterlace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('imageinterlace() expects 1 to 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imageinterlace', 1);
        $enable = null;
        if (2 === $argc) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $enable = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'imageinterlace', 2, 'enable');
            }
        }
        $frame->returnVar->bool(VmGd::interlace($image, $enable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imageinterlace() is VM-only in this compiler build (#20460)');
    }
}
