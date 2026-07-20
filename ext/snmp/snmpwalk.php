<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmpwalk() — walk OID subtree (php-src ext/snmp/snmp.c; #6070). */
final class snmpwalk extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmpwalk');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmpwalk', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmpwalk() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmpwalk', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmpwalk', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmpwalk', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmpwalk', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmpwalk', 4, 'retries');
        }
        VmSnmp::proceduralWalk($ctx, $frame, 'snmpwalk', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}