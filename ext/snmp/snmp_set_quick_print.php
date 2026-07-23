<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** snmp_set_quick_print() (php-src ext/snmp/snmp.c; #22250). */
final class snmp_set_quick_print extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_set_quick_print');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_set_quick_print', 1, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $enable = VmSnmp::coerceBoolArg($frame->calledArgs[0], 'snmp_set_quick_print', 0, 'enable');
        $frame->returnVar->bool(VmSnmp::setQuickPrint($enable));
    }
}
