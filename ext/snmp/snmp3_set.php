<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp3_set() — SNMPv3 set (php-src ext/snmp/snmp.c; #22250). */
final class snmp3_set extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp3_set');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp3_set', 10, 12);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp3_set() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp3_set', 0, 'hostname');
        $sec = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp3_set', 1, 'security_name');
        VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'snmp3_set', 2, 'security_level');
        VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'snmp3_set', 3, 'auth_protocol');
        VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'snmp3_set', 4, 'auth_passphrase');
        VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'snmp3_set', 5, 'privacy_protocol');
        VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'snmp3_set', 6, 'privacy_passphrase');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[7], 'snmp3_set', 7);
        VmSnmp::coerceTypeOrValue($frame->calledArgs[8], 'snmp3_set', 8, 'type');
        VmSnmp::coerceTypeOrValue($frame->calledArgs[9], 'snmp3_set', 9, 'value');
        if (\count($frame->calledArgs) >= 11) {
            VmSnmp::coerceIntArg($frame->calledArgs[10], 'snmp3_set', 10, 'timeout');
        }
        if (\count($frame->calledArgs) >= 12) {
            VmSnmp::coerceIntArg($frame->calledArgs[11], 'snmp3_set', 11, 'retries');
        }
        VmSnmp::proceduralSet($ctx, $frame, 'snmp3_set', $hostname, $sec, $objectId);
        $frame->returnVar->bool(false);
    }
}
