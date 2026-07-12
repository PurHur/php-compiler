<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * date_sunset() — procedural sunset helper (ext/date/php_date.c, #6137).
 *
 * VM: VmDate host bridge. JIT/AOT: compile-time literal baking via JitDateSunFunc (#6137).
 */
final class date_sunset extends Internal
{
    public function __construct()
    {
        parent::__construct('date_sunset');
    }

    public function execute(Frame $frame): void
    {
        VmEngineBuiltinDeprecation::emitFunction($frame, 'date_sunset');
        $parsed = VmDateSunFunc::parseArgs($frame, 'date_sunset');
        $result = VmDate::dateSunset(
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
        return JitDateSunFunc::invoke($context, true, 'date_sunset', ...$args);
    }
}
