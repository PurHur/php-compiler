<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** snmp_get_quick_print() (php-src ext/snmp/snmp.c; #22250). */
final class snmp_get_quick_print extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_get_quick_print');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_get_quick_print', 0, 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmSnmp::getQuickPrint());
    }
}
