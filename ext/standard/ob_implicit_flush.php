<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\OutputBuffer;
use PHPLLVM\Value;

/**
 * ob_implicit_flush() — auto-flush output buffers after each write (VM + JIT/AOT, #3401).
 *
 * php-src: ext/standard/output.c — PHP_FUNCTION(ob_implicit_flush)
 */
final class ob_implicit_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_implicit_flush');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('ob_implicit_flush() accepts at most one argument in this compiler build');
        }
        $on = true;
        if (1 === $argc) {
            $on = $frame->calledArgs[0]->resolveIndirect()->toBool();
        }
        OutputBuffer::setImplicitFlush($on);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObImplicitFlush::invoke($context, ...$args);
    }
}
