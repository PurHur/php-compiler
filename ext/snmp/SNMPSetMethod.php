<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::set() — set OID via session (php-src snmp_class.c; #22244). */
final class SNMPSetMethod extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError('SNMP::set() expects at least 3 arguments, '.($argc - 1).' given');
        }
        if ($argc > 4) {
            throw new \ArgumentCountError('SNMP::set() expects exactly 3 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::set()');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[1], 'SNMP::set', 1);
        VmSnmp::coerceTypeOrValue($frame->calledArgs[2], 'SNMP::set', 2, 'type');
        VmSnmp::coerceTypeOrValue($frame->calledArgs[3], 'SNMP::set', 3, 'value');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SNMP::set() requires a VM context');
        }
        VmSnmp::instanceSet($receiver, $ctx, $frame, $objectId);
        $frame->returnVar->bool(false);
    }
}
