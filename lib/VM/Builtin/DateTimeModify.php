<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;

/**
 * DateTime::modify() / DateTimeImmutable::modify() — VM (#6132, #10733, #22663, #28524, #29818).
 *
 * php-src ext/date/php_date.c — zim_DateTime_modify / zim_DateTimeImmutable_modify:
 * PHP 8.2 warning+false; PHP 8.3+ EH_THROW DateMalformedStringException for both
 * object methods. Procedural date_modify() stays warning+false on all profiles.
 * Caller strict_types → TypeError on null $modifier (Z_PARAM_STR; #29818).
 */
final class DateTimeModify extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('modify');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::modify() called without $this');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::modify()'
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // User arity excludes $this — php-src zim_DateTime_modify (#30834).
        $this->requireExactUserArgCount($frame, "{$label}::modify", 1);
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29818).
        // Frame arg 1 includes $this; user-visible Argument #1 ($modifier).
        $modifier = VmString::internalMethodStringArgForFrame(
            $frame,
            1,
            "{$label}::modify",
            0,
            'modifier'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $throwOnMalformed = CompilerVersion::advertisesDateExceptionHierarchy();
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $updated = DateTimeSupport::tryWithModify($receiver, $modifier);
            if (false === $updated) {
                self::failModify($frame, $label, $modifier, $throwOnMalformed);
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->object($updated);

            return;
        }
        if (!DateTimeSupport::tryModify($receiver, $modifier)) {
            self::failModify($frame, $label, $modifier, $throwOnMalformed);
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
        $message = self::malformedModifyMessage($label, $modifier);
        if ($throwOnMalformed) {
            // php-src zim_DateTime*_modify — zend_replace_error_handling(EH_THROW, …).
            // Exception message includes the method prefix (same as the E_WARNING text).
            DateTimeSupport::throwDateMalformedStringException($message);
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /** Match php_date_modify / timelib first-error text with zim_* method prefix. */
    private static function malformedModifyMessage(string $label, string $modifier): string
    {
        // php-src timelib: empty input → position char is a space + "Empty string" (#29301).
        if ('' === $modifier) {
            return "{$label}::modify(): Failed to parse time string () at position 0 ( ): Empty string";
        }
        $pos = $modifier[0];
        // Alphabetic tokens try timezone lookup; punctuation/digits/signs → "Unexpected character"
        // (@@@; #31597 — same mapping as DateInterval::createFromDateString #31575).
        $reason = \ctype_alpha($pos)
            ? 'The timezone could not be found in the database'
            : 'Unexpected character';

        return "{$label}::modify(): Failed to parse time string ({$modifier}) at position 0 ({$pos}): {$reason}";
    }
}
