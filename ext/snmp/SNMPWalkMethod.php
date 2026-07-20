<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::walk() method handler — walk OID via session (php-src snmp_class.c; #6070). */
final class SNMPWalkMethod extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('walk');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('SNMP::walk() expects at least 1 argument, '.($argc - 1).' given');
        }
        if ($argc > 5) {
            throw new \ArgumentCountError('SNMP::walk() expects at most 4 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::walk()');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[1], 'SNMP::walk', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SNMP::walk() requires a VM context');
        }
        VmSnmp::instanceWalk($receiver, $ctx, $frame, $objectId);
        $frame->returnVar->bool(false);
    }
}