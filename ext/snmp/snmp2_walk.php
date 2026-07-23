<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp2_walk() — SNMPv2c (php-src ext/snmp/snmp.c; #22250). */
final class snmp2_walk extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp2_walk');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp2_walk', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp2_walk() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp2_walk', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp2_walk', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmp2_walk', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmp2_walk', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmp2_walk', 4, 'retries');
        }
        VmSnmp::proceduralWalk($ctx, $frame, 'snmp2_walk', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
