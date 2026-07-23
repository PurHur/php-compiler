<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** snmp_set_valueretrieval() (php-src ext/snmp/snmp.c; #22250). */
final class snmp_set_valueretrieval extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_set_valueretrieval');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_set_valueretrieval', 1, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $method = VmSnmp::coerceIntArg($frame->calledArgs[0], 'snmp_set_valueretrieval', 0, 'method');
        $frame->returnVar->bool(VmSnmp::setValueRetrieval($method));
    }
}
