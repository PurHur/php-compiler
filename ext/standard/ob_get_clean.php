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
 * ob_get_clean() — return active buffer and end buffering (VM; JIT scaffold {@see JitObGetClean}, #118, #1056).
 */
final class ob_get_clean extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_clean');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'ob_get_clean() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === OutputBuffer::getLevel()) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string(OutputBuffer::getClean());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObGetClean::invoke($context, ...$args);
    }
}
