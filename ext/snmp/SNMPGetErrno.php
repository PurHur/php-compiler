<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::getErrno() — last error code (php-src snmp_class.c; #6070). */
final class SNMPGetErrno extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrno');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('SNMP::getErrno() expects exactly 0 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::getErrno()');
        $state = VmSnmp::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($state->errno);
        }
    }
}