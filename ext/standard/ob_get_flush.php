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
 * ob_get_flush() — return active buffer and flush to parent without ending (VM; JIT {@see JitObGetFlush}, #3753).
 *
 * php-src: ext/standard/output.c — php_ob_get_flush()
 */
final class ob_get_flush extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_flush');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_get_flush() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = OutputBuffer::getFlush();
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitObGetFlush::invoke($context, ...$args);
    }
}
