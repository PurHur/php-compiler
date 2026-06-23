<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;

/** DateTime::modify() / DateTimeImmutable::modify() — VM (#6132, #10733). */
final class DateTimeModify extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('modify');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DateTime::modify() expects exactly 1 argument');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::modify()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        $modifier = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            "{$label}::modify",
            0,
            'modifier'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $updated = DateTimeSupport::tryWithModify($receiver, $modifier);
            if (false === $updated) {
                self::warnModifyFailure($frame, $label, $modifier);
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->object($updated);

            return;
        }
        if (!DateTimeSupport::tryModify($receiver, $modifier)) {
            self::warnModifyFailure($frame, $label, $modifier);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($receiver);
    }

    private static function warnModifyFailure(Frame $frame, string $label, string $modifier): void
    {
        $pos = '' !== $modifier ? $modifier[0] : 'n';
        $frame->vmContext->errors->triggerError(
            "{$label}::modify(): Failed to parse time string ({$modifier}) at position 0 ({$pos}): The timezone could not be found in the database",
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
