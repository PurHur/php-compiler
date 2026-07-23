<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp2_get() — SNMPv2c (php-src ext/snmp/snmp.c; #22250). */
final class snmp2_get extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp2_get');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp2_get', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp2_get() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp2_get', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp2_get', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmp2_get', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmp2_get', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmp2_get', 4, 'retries');
        }
        VmSnmp::proceduralGet($ctx, $frame, 'snmp2_get', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
