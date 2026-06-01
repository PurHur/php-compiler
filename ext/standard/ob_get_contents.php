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
 * ob_get_contents() — return active buffer without ending (ext/standard/output.c, issue #3236).
 */
final class ob_get_contents extends Internal
{
    public function __construct()
    {
        parent::__construct('ob_get_contents');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('ob_get_contents() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $contents = OutputBuffer::getContents();
        if (null === $contents) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($contents);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ob_get_contents() is VM-only in this compiler build');
    }
}
