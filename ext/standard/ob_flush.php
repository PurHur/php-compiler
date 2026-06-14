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
 * ob_flush() — flush active buffer without ending level (ext/standard/output.c, #3588; JIT {@see JitObFlush}).
 */
final class ob_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_flush');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_flush() takes no arguments');
        }
        if (null === $frame->returnVar) {
            OutputBuffer::flushBuffer();

            return;
        }
        $frame->returnVar->bool(OutputBuffer::flushBuffer());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObFlush::invoke($context, ...$args);
    }
}
