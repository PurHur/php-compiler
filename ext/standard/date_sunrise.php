<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * date_sunrise() — procedural sunrise helper (ext/date/php_date.c, #6137).
 *
 * VM: VmDate host bridge. JIT/AOT: compile-time literal baking via JitDateSunFunc (#6137).
 */
final class date_sunrise extends Internal
{
    public function __construct()
    {
        parent::__construct('date_sunrise');
    }

    public function execute(Frame $frame): void
    {
        VmEngineBuiltinDeprecation::emitFunction($frame, 'date_sunrise');
        $parsed = VmDateSunFunc::parseArgs($frame, 'date_sunrise');
        $result = VmDate::dateSunrise(
            $parsed['timestamp'],
            $parsed['returnFormat'],
            $parsed['latitude'],
            $parsed['longitude'],
            $parsed['zenith'],
            $parsed['gmtOffset'],
            $parsed['argc']
        );
        VmDate::writeSunFuncReturn($frame, $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateSunFunc::invoke($context, false, 'date_sunrise', ...$args);
    }
}
