<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** extract() — import string-keyed array variables into the caller scope (VM + JIT). */
final class extract_ extends Internal
{
    public function __construct()
    {
        parent::__construct('extract');
    }

    public function execute(Frame $frame): void
    {
        $count = VmScope::extract($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // LLVM scope import for dynamic string keys is tracked in issue #275 (VM path is complete).
        throw new \LogicException('extract() is not implemented for JIT in this compiler build');
    }
}
