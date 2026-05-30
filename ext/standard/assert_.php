<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * assert() — runtime invariant check (ext/standard/assert.c; issue #3157).
 *
 * php-src: ext/standard/assert.c, Zend/zend_assertions.c
 */
final class assert_ extends Internal
{
    public function __construct()
    {
        parent::__construct('assert');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('assert() requires one or two arguments');
        }
        $description = 2 === $argc ? $frame->calledArgs[1] : null;
        $result = VmAssert::evaluate($frame, $frame->calledArgs[0], $description);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitAssert::invoke($context, ...$args);
    }
}
