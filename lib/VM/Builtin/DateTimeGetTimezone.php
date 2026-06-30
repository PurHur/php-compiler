<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTime::getTimezone() / DateTimeImmutable::getTimezone() — VM (#10946). */
final class DateTimeGetTimezone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimezone');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DateTime::getTimezone() called without $this');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTime::getTimezone() requires VM context');
        }
        $receiver = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            'DateTime::getTimezone()',
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            DateTimeSupport::getTimezoneObject($receiver, $frame->vmContext)
        );
    }
}
