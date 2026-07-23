<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmpgetnext() — fetch next OID (php-src ext/snmp/snmp.c; #22244). */
final class snmpgetnext extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmpgetnext');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmpgetnext', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmpgetnext() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmpgetnext', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmpgetnext', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmpgetnext', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmpgetnext', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmpgetnext', 4, 'retries');
        }
        VmSnmp::proceduralGetNext($ctx, $frame, 'snmpgetnext', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
