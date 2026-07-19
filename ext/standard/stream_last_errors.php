<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_last_errors() — PHP 8.6 stream error store snapshot (#21020).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_last_errors)
 */
final class stream_last_errors extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_last_errors');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('stream_last_errors() expects exactly 0 arguments, %d given', \count($frame->calledArgs))
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $frame->returnVar->copyFrom(VmStreamErrorStore::lastErrorsVariable($frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_last_errors() is VM-only in this compiler build (issue #21020)');
    }
}
