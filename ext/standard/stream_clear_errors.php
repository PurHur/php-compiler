<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_clear_errors() — clear PHP 8.6 stream error store (#21020).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_clear_errors)
 */
final class stream_clear_errors extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_clear_errors');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('stream_clear_errors() expects exactly 0 arguments, %d given', \count($frame->calledArgs))
            );
        }
        VmStreamErrorStore::clear();
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_clear_errors() is VM-only in this compiler build (issue #21020)');
    }
}
