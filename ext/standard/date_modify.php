<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * date_modify() — apply relative modifier to DateTime in place (ext/date/php_date.c, #4604).
 */
final class date_modify extends Internal
{
    public function __construct()
    {
        parent::__construct('date_modify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'date_modify() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dt = DateTimeSupport::requireDateTime(
            $frame->calledArgs[0],
            'date_modify()',
            1,
            'object',
            $frame->vmContext
        );
        $modifier = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'date_modify',
            1,
            'modifier'
        );
        if (!DateTimeSupport::tryModify($dt, $modifier)) {
            // php-src php_date_modify / timelib: empty → "( ): Empty string"; else timezone-db (#29302).
            if ('' === $modifier) {
                $warning = 'date_modify(): Failed to parse time string () at position 0 ( ): Empty string';
            } else {
                $warning = \sprintf(
                    'date_modify(): Failed to parse time string (%s) at position 0 (%s): The timezone could not be found in the database',
                    $modifier,
                    $modifier[0]
                );
            }
            $frame->vmContext->errors->triggerError(
                $warning,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($frame->calledArgs[0]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateMutation::invokeModify($context, ...$args);
    }
}
