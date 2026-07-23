<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::getnext() — fetch next OID via session (php-src snmp_class.c; #22244). */
final class SNMPGetNextMethod extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('getnext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('SNMP::getnext() expects at least 1 argument, '.($argc - 1).' given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('SNMP::getnext() expects exactly 1 argument, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::getnext()');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[1], 'SNMP::getnext', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SNMP::getnext() requires a VM context');
        }
        VmSnmp::instanceGetNext($receiver, $ctx, $frame, $objectId);
        $frame->returnVar->bool(false);
    }
}
