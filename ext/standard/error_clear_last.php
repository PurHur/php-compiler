<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * error_clear_last() — clear last PHP error state (ext/standard/error.c parity, issue #3158).
 */
final class error_clear_last extends Internal
{
    public function __construct()
    {
        parent::__construct('error_clear_last');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('error_clear_last() takes no arguments');
        }
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->clearLastError();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('error_clear_last() takes no arguments');
        }
        JitErrorGetLast::clear($context);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
