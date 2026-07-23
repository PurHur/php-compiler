<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmprealwalk() — walk OID subtree with full OID keys (php-src ext/snmp/snmp.c; #22244). */
final class snmprealwalk extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmprealwalk');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmprealwalk', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmprealwalk() requires a VM context');
        }
        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmprealwalk', 0, 'hostname');
        $community = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'snmprealwalk', 1, 'community');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[2], 'snmprealwalk', 2);
        if (\count($frame->calledArgs) >= 4) {
            VmSnmp::coerceIntArg($frame->calledArgs[3], 'snmprealwalk', 3, 'timeout');
        }
        if (\count($frame->calledArgs) >= 5) {
            VmSnmp::coerceIntArg($frame->calledArgs[4], 'snmprealwalk', 4, 'retries');
        }
        VmSnmp::proceduralRealWalk($ctx, $frame, 'snmprealwalk', $hostname, $community, $objectId);
        $frame->returnVar->bool(false);
    }
}
