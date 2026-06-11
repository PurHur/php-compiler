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
 * VM: VmDate host bridge. JIT/AOT: deferred (VM-first v1).
 */
final class date_sunrise extends Internal
{
    public function __construct()
    {
        parent::__construct('date_sunrise');
    }

    public function execute(Frame $frame): void
    {
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
        throw new \LogicException('date_sunrise() is not implemented for JIT in this compiler build (issue #6137)');
    }
}
