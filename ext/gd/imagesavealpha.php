<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imagesavealpha() — include alpha channel when encoding PNG/WebP (php-src ext/gd/gd.c; #6535).
 */
final class imagesavealpha extends Internal
{
    public function __construct()
    {
        parent::__construct('imagesavealpha');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('imagesavealpha() expects exactly 2 arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $image = VmGd::requireGdImage($frame->calledArgs[0], 'imagesavealpha', 1);
        $enable = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'imagesavealpha', 2, 'enable');
        $frame->returnVar->bool(VmGd::setSaveAlpha($image, $enable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('imagesavealpha() is VM-only in this compiler build (#6535)');
    }
}
