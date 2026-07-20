<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmpget() — fetch one OID (php-src ext/snmp/snmp.c; #6070). */
final class snmpget extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmpget');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmpget', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmpget() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmpget', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmpget', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmpget', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmpget', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmpget', 4, 'retries');
        }
        VmSnmp::proceduralGet($ctx, $frame, 'snmpget', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}