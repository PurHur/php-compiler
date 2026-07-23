<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp2_set() — SNMPv2c set (php-src ext/snmp/snmp.c; #22250). */
final class snmp2_set extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp2_set');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp2_set', 5, 7);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp2_set() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp2_set', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp2_set', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmp2_set', 2);
        VmSnmp::coerceTypeOrValue($frame->calledArgs[3], 'snmp2_set', 3, 'type');
        VmSnmp::coerceTypeOrValue($frame->calledArgs[4], 'snmp2_set', 4, 'value');
        if (\count($frame->calledArgs) >= 6) {
            VmSnmp::coerceIntArg($frame->calledArgs[5], 'snmp2_set', 5, 'timeout');
        }
        if (\count($frame->calledArgs) >= 7) {
            VmSnmp::coerceIntArg($frame->calledArgs[6], 'snmp2_set', 6, 'retries');
        }
        VmSnmp::proceduralSet($ctx, $frame, 'snmp2_set', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
