<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for gettext builtins; JIT/AOT deferred v1 (#3449).
 */
abstract class GettextFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #3449)');
    }

    protected function requireArgCount(Frame $frame, int $expected, int $max = null): void
    {
        $argc = \count($frame->calledArgs);
        $max ??= $expected;
        if ($argc < $expected || $argc > $max) {
            throw new \ArgumentCountError(sprintf(
                '%s() expects %s %d argument%s, %d given',
                $this->getName(),
                $expected === $max ? 'exactly' : 'at most',
                $expected === $max ? $expected : $max,
                1 === ($expected === $max ? $expected : $max) ? '' : 's',
                $argc
            ));
        }
    }
}
