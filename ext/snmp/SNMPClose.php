<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::close() — mark session closed (php-src snmp_class.c; #6070). */
final class SNMPClose extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('SNMP::close() expects exactly 0 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::close()');
        $ok = VmSnmp::close($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}