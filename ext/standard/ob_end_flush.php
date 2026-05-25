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
 * ob_end_flush() — flush active buffer and end buffering (VM; JIT scaffold {@see JitObEndFlush}, #118, #1056).
 */
final class ob_end_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_end_flush');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_end_flush() takes no arguments');
        }
        if (0 === OutputBuffer::getLevel()) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        OutputBuffer::endFlush();
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_end_flush() is not implemented for JIT in this compiler build (#118)');
    }
}
