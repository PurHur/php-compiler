<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmString;

/** snmp_read_mib() (php-src ext/snmp/snmp.c; #22250). */
final class snmp_read_mib extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_read_mib');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_read_mib', 1, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('snmp_read_mib() requires a VM context');
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'snmp_read_mib', 0, 'filename');
        $frame->returnVar->bool(VmSnmp::readMib($ctx, $frame, $filename));
    }
}
