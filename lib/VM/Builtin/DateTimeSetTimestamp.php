<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::setTimestamp() / DateTimeImmutable::setTimestamp() — VM (#10946). */
final class DateTimeSetTimestamp extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setTimestamp');
    }

    public function execute(Frame $frame): void
    {
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::setTimestamp()',
            $frame->vmContext
        );
        $label = DateTimeSupport::isDateTimeImmutable($receiver) ? 'DateTimeImmutable' : 'DateTime';
        // php-src zim_DateTime_setTimestamp — ZEND_PARSE_PARAMETERS exactly 1 (#30991).
        $this->requireExactUserArgCount($frame, "{$label}::setTimestamp", 1);
        // Z_PARAM_LONG — caller strict_types → TypeError on null (#29841).
        // Frame arg 1 includes $this; user-visible Argument #1 ($timestamp).
        $timestamp = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            "{$label}::setTimestamp",
            1,
            'timestamp'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $frame->returnVar->object(DateTimeSupport::withTimestamp($receiver, $timestamp));

            return;
        }
        DateTimeSupport::setTimestamp($receiver, $timestamp);
        $frame->returnVar->object($receiver);
    }
}
