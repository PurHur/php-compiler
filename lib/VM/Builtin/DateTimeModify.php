<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;

/**
 * DateTime::modify() / DateTimeImmutable::modify() — VM (#6132, #10733, #22663).
 *
 * php-src ext/date/php_date.c — mutable zim_DateTime_modify: E_WARNING + false on all
 * profiles (date_modify() unchanged). Immutable zim_DateTimeImmutable_modify: PHP 8.2
 * warning+false; PHP 8.3+ EH_THROW DateMalformedStringException (#22663, #24296).
 */
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
                self::failModify(
                    $frame,
                    $label,
                    $modifier,
                    CompilerVersion::advertisesDateExceptionHierarchy()
                );
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->object($updated);

            return;
        }
        if (!DateTimeSupport::tryModify($receiver, $modifier)) {
            // Mutable DateTime::modify() — warning + false always (php-src date_object_modify).
            self::failModify($frame, $label, $modifier, false);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($receiver);
    }

    private static function failModify(
        Frame $frame,
        string $label,
        string $modifier,
        bool $throwOnMalformed
    ): void {
        if ($throwOnMalformed) {
            // php-src zim_DateTime*_modify — zend_replace_error_handling(EH_THROW, …).
            DateTimeSupport::throwDateMalformedStringException(
                self::malformedModifyMessage($modifier)
            );
        }
        self::warnModifyFailure($frame, $label, $modifier);
    }

    /** Match php_date_modify / timelib first-error text (also used by DateTime::__construct). */
    private static function malformedModifyMessage(string $modifier): string
    {
        $pos = '' !== $modifier ? $modifier[0] : 'n';

        return "Failed to parse time string ({$modifier}) at position 0 ({$pos}): The timezone could not be found in the database";
    }

    private static function warnModifyFailure(Frame $frame, string $label, string $modifier): void
    {
        $frame->vmContext->errors->triggerError(
            "{$label}::modify(): ".self::malformedModifyMessage($modifier),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
