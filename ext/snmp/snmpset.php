<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmpset() — set OID value (php-src ext/snmp/snmp.c; #22244). */
final class snmpset extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmpset');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmpset', 5, 7);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmpset() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmpset', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmpset', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmpset', 2);
        VmSnmp::coerceTypeOrValue($frame->calledArgs[3], 'snmpset', 3, 'type');
        VmSnmp::coerceTypeOrValue($frame->calledArgs[4], 'snmpset', 4, 'value');
        if (\count($frame->calledArgs) >= 6) {
            VmSnmp::coerceIntArg($frame->calledArgs[5], 'snmpset', 5, 'timeout');
        }
        if (\count($frame->calledArgs) >= 7) {
            VmSnmp::coerceIntArg($frame->calledArgs[6], 'snmpset', 6, 'retries');
        }
        VmSnmp::proceduralSet($ctx, $frame, 'snmpset', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
