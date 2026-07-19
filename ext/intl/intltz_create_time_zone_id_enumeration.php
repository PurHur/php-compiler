<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intltz_create_time_zone_id_enumeration() — procedural IntlTimeZone::createTimeZoneIDEnumeration
 * (php-src timezone_methods.cpp / timezone.stub.php; #20925).
 */
final class intltz_create_time_zone_id_enumeration extends Internal
{
    public function __construct()
    {
        parent::__construct('intltz_create_time_zone_id_enumeration');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'intltz_create_time_zone_id_enumeration() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $zoneType = VmIntlDateFormatter::coerceIntArg(
            $frame->calledArgs[0],
            'intltz_create_time_zone_id_enumeration',
            0,
            'type'
        );
        $region = null;
        if ($argc >= 2) {
            $r = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $r->type) {
                $region = VmString::coerceStringBuiltinArg(
                    $r,
                    'intltz_create_time_zone_id_enumeration',
                    1,
                    'region'
                );
            }
        }
        $rawOffset = null;
        if ($argc >= 3) {
            $o = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $o->type) {
                $rawOffset = VmIntlDateFormatter::coerceIntArg(
                    $o,
                    'intltz_create_time_zone_id_enumeration',
                    2,
                    'rawOffset'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createTimeZoneIDEnumeration(
            $frame->vmContext,
            $zoneType,
            $region,
            $rawOffset
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intltz_create_time_zone_id_enumeration() is not implemented for JIT in this compiler build (issue #20925)');
    }
}