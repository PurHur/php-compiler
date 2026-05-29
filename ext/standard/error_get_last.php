<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * error_get_last() — last PHP error state (ext/standard/error.c parity, issue #3158).
 */
final class error_get_last extends Internal
{
    public function __construct()
    {
        parent::__construct('error_get_last');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('error_get_last() takes no arguments');
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        $frame->returnVar->copyFrom($frame->vmContext->errors->getLastErrorVariable());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('error_get_last() takes no arguments');
        }

        return JitErrorGetLast::invoke($context);
    }
}
