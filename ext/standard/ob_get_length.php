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
 * ob_get_length() — byte length of active output buffer (ext/standard/output.c, issue #3236).
 */
final class ob_get_length extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_length');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_get_length() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $length = OutputBuffer::getLength();
        if (null === $length) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($length);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_get_length() is VM-only in this compiler build');
    }
}
