<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::__construct() — store session params (php-src snmp_class.c; #6070). */
final class SNMPConstruct extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'SNMP::__construct() expects at least 3 arguments, '.($argc - 1).' given'
            );
        }
        if ($argc > 6) {
            throw new \ArgumentCountError(
                'SNMP::__construct() expects at most 5 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SNMP::__construct()');
        $version = $this->intArg($frame->calledArgs[1], 'SNMP::__construct', 1, 'version');
        $hostname = $this->stringArg($frame->calledArgs[2], 'SNMP::__construct', 2, 'hostname');
        $community = $this->stringArg($frame->calledArgs[3], 'SNMP::__construct', 3, 'community');
        $timeout = -1;
        $retries = -1;
        if ($argc >= 5) {
            $timeout = $this->intArg($frame->calledArgs[4], 'SNMP::__construct', 4, 'timeout');
        }
        if ($argc >= 6) {
            $retries = $this->intArg($frame->calledArgs[5], 'SNMP::__construct', 5, 'retries');
        }
        VmSnmp::initObject($receiver, $version, $hostname, $community, $timeout, $retries);
    }
}