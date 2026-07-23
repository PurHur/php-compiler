<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** snmp_set_oid_numeric_print() — alias of oid output format (php-src; #22250). */
final class snmp_set_oid_numeric_print extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_set_oid_numeric_print');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_set_oid_numeric_print', 1, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $format = VmSnmp::coerceIntArg($frame->calledArgs[0], 'snmp_set_oid_numeric_print', 0, 'format');
        $frame->returnVar->bool(VmSnmp::setOidOutputFormat($format));
    }
}
