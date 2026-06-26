<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\ScopeBuiltinHelper;
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
        if (\count($args) < 1 || \count($args) > 3) {
            throw new \LogicException('extract() requires one to three arguments in this compiler build');
        }
        $flags = 2 <= \count($args) ? $args[1] : null;
        $prefix = 3 === \count($args) ? $args[2] : null;

        return ScopeBuiltinHelper::extract($context, $args[0], $flags, $prefix);
    }
}
