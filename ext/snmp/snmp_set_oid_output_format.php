<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** snmp_set_oid_output_format() (php-src ext/snmp/snmp.c; #22250). */
final class snmp_set_oid_output_format extends SnmpFunction
{
    public function __construct()
    {
        parent::__construct('snmp_set_oid_output_format');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'snmp_set_oid_output_format', 1, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $format = VmSnmp::coerceIntArg($frame->calledArgs[0], 'snmp_set_oid_output_format', 0, 'format');
        $frame->returnVar->bool(VmSnmp::setOidOutputFormat($format));
    }
}
