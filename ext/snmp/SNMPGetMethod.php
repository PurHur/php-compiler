<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::get() method handler — fetch OID via session (php-src snmp_class.c; #6070). */
final class SNMPGetMethod extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('SNMP::get() expects at least 1 argument, '.($argc - 1).' given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('SNMP::get() expects exactly 1 argument, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::get()');
        $objectId = VmSnmp::coerceObjectId($frame->calledArgs[1], 'SNMP::get', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SNMP::get() requires a VM context');
        }
        VmSnmp::instanceGet($receiver, $ctx, $frame, $objectId);
        $frame->returnVar->bool(false);
    }
}