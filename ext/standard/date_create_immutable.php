<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * date_create_immutable() — procedural DateTimeImmutable factory (ext/date/php_date.c, #4124).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_create_immutable)
 */
final class date_create_immutable extends Internal
{
    public function __construct()
    {
        parent::__construct('date_create_immutable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'date_create_immutable() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $time = 'now';
        if ($argc >= 1) {
            InternalStrictArg::rejectNullString($frame->calledArgs[0], 'date_create_immutable', 'datetime', 0, $frame);
            $time = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'date_create_immutable',
                0,
                'datetime'
            );
        }
        $timezone = null;
        if ($argc >= 2) {
            $tzVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tzVar->type) {
                $timezone = DateTimeSupport::requireDateTimeZone(
                    $frame->calledArgs[1],
                    'date_create_immutable(): Argument #2 ($timezone)'
                );
            }
        }

        $created = DateTimeSupport::tryNewDateTimeImmutableVariable($frame->vmContext, $time, $timezone);
        if (null === $created) {
            $frame->vmContext->errors->triggerError(
                'date_create_immutable(): Failed to parse time string ('.$time.')',
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($created): void {
            $ret->copyFrom($created);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateCreate::invoke($context, true, ...$args);
    }
}
