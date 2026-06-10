<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::modify() / DateTimeImmutable::modify() — VM (#6132). */
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
        if (DateTimeSupport::isDateTimeImmutable($receiver)) {
            $updated = DateTimeSupport::withModify($receiver, $modifier);
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($updated);
            }

            return;
        }
        DateTimeSupport::modify($receiver, $modifier);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($receiver);
        }
    }
}
