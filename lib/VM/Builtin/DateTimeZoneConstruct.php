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
        $argc = \count($frame->calledArgs);
        // User arity excludes $this — php-src zim_DateTimeZone___construct (#31068).
        $userArgc = max(0, $argc - 1);
        if (1 !== $userArgc) {
            throw new \ArgumentCountError(\sprintf(
                'DateTimeZone::__construct() expects exactly 1 argument, %d given',
                $userArgc
            ));
        }
        // php_date.stub.php — null DEP+coerce on 8.4 forward profile (#21369, re-#20959)
        $timezone = VmString::trimFamilyStringArgForFrame(
            $frame,
            1,
            'DateTimeZone::__construct',
            0,
            'timezone'
        );
        $receiver = DateTimeSupport::requireDateTimeZone($frame->calledArgs[0], 'DateTimeZone::__construct()');
        DateTimeSupport::initDateTimeZone($receiver, $timezone);
    }
}
