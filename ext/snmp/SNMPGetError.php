<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::getError() — last error string (php-src snmp_class.c; #6070). */
final class SNMPGetError extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('getError');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('SNMP::getError() expects exactly 0 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::getError()');
        $state = VmSnmp::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($state->error);
        }
    }
}