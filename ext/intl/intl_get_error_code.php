<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intl_get_error_code() stub — no ICU yet (php-src ext/intl/intl_error.c; #5774).
 */
final class intl_get_error_code extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(0);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
