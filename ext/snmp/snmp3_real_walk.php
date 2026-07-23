<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp3_real_walk() — SNMPv3 (php-src ext/snmp/snmp.c; #22250). */
final class snmp3_real_walk extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp3_real_walk');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp3_real_walk', 8, 10);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp3_real_walk() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp3_real_walk', 0, 'hostname');
        VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp3_real_walk', 1, 'security_name');
        VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'snmp3_real_walk', 2, 'security_level');
        VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'snmp3_real_walk', 3, 'auth_protocol');
        VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'snmp3_real_walk', 4, 'auth_passphrase');
        VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'snmp3_real_walk', 5, 'privacy_protocol');
        VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'snmp3_real_walk', 6, 'privacy_passphrase');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[7], 'snmp3_real_walk', 7);
        if (\count($frame->calledArgs) >= 9) {
            VmSnmp::coerceIntArg($frame->calledArgs[8], 'snmp3_real_walk', 8, 'timeout');
        }
        if (\count($frame->calledArgs) >= 10) {
            VmSnmp::coerceIntArg($frame->calledArgs[9], 'snmp3_real_walk', 9, 'retries');
        }
        // community unused for v3 — pass security_name slot for warning context
        $sec = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmp3_real_walk', 1, 'security_name');
        VmSnmp::proceduralRealWalk($ctx, $frame, 'snmp3_real_walk', $hostname, $sec, $objectId);
        $frame->returnVar->bool(false);
    }
}
