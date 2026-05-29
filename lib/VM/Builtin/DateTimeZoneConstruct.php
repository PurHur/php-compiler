<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::__construct(string $timezone) — VM (#3072). */
final class DateTimeZoneConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DateTimeZone::__construct() expects exactly 1 argument');
        }
        $timezone = VmReflection::stringArg($frame->calledArgs[1], 'DateTimeZone::__construct() timezone');
        $receiver = DateTimeSupport::requireDateTimeZone($frame->calledArgs[0], 'DateTimeZone::__construct()');
        DateTimeSupport::initDateTimeZone($receiver, $timezone);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
