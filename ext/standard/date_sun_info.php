<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * date_sun_info() — sun/twilight timestamp array (ext/date/php_date.c, #6831).
 *
 * VM: VmDate host bridge. JIT/AOT: compile-time literal baking via JitDateSunInfo.
 */
final class date_sun_info extends Internal
{
    public function __construct()
    {
        parent::__construct('date_sun_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'date_sun_info() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $time = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'date_sun_info', 1, 'timestamp');
        $latitude = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'date_sun_info',
            2,
            'latitude'
        );
        $longitude = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[2]->resolveIndirect(),
            'date_sun_info',
            3,
            'longitude'
        );
        $frame->returnVar->array(VmDate::dateSunInfo($time, $latitude, $longitude));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateSunInfo::invoke($context, ...$args);
    }
}
