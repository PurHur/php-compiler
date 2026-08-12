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
 * ob_get_level() — active output buffer depth (VM; JIT scaffold {@see JitObGetLevel}, #118, #1056).
 */
final class ob_get_level extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_level');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'ob_get_level() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(OutputBuffer::getLevel());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObGetLevel::invoke($context, ...$args);
    }
}
