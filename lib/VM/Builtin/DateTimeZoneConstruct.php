<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\DateTimeSupport;

/** DateTimeZone::__construct(string $timezone) — VM (#3072, #20959). */
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
        // Z_PARAM_STR — null TypeError on PROFILE=8.4 (php_date.stub.php; #20959)
        $timezone = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[1],
            'DateTimeZone::__construct',
            0,
            'timezone'
        );
        $receiver = DateTimeSupport::requireDateTimeZone($frame->calledArgs[0], 'DateTimeZone::__construct()');
        DateTimeSupport::initDateTimeZone($receiver, $timezone);
    }
}
