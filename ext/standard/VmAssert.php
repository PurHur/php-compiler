<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * assert() VM semantics (ext/standard/assert.c; issue #3157).
 *
 * Supported ini subset: assertions always evaluated; failed assertions emit
 * E_USER_WARNING (assert.exception=0). AssertionError throw deferred (#195).
 */
final class VmAssert
{
    public static function evaluate(
        Frame $frame,
        Variable $assertion,
        ?Variable $description = null
    ): bool {
        if (boolval::isTruthy($assertion)) {
            return true;
        }
        self::fail($frame, $description);

        return false;
    }

    private static function fail(Frame $frame, ?Variable $description): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('assert() requires VM context');
        }
        $message = 'assert(): assert(false) failed';
        if (null !== $description) {
            $desc = $description->resolveIndirect();
            if (Variable::TYPE_STRING === $desc->type) {
                $message = 'Assertion failed: '.$desc->toString();
            }
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_USER_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
