<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\Frame;

/** SNMP::setSecurity() — SNMPv3 params on session (php-src snmp_class.c; #22250). */
final class SNMPSetSecurity extends SnmpClassMethod
{
    public function __construct()
    {
        parent::__construct('setSecurity');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('SNMP::setSecurity() expects at least 1 argument, '.($argc - 1).' given');
        }
        if ($argc > 8) {
            throw new \ArgumentCountError('SNMP::setSecurity() expects at most 7 arguments, '.($argc - 1).' given');
        }
        $receiver = $this->receiver($frame, 'SNMP::setSecurity()');
        $securityLevel = $this->stringArg($frame->calledArgs[1], 'SNMP::setSecurity', 1, 'securityLevel');
        $authProtocol = '';
        $authPassphrase = '';
        $privacyProtocol = '';
        $privacyPassphrase = '';
        $contextName = '';
        $contextEngineId = '';
        if ($argc >= 3) {
            $authProtocol = $this->stringArg($frame->calledArgs[2], 'SNMP::setSecurity', 2, 'authProtocol');
        }
        if ($argc >= 4) {
            $authPassphrase = $this->stringArg($frame->calledArgs[3], 'SNMP::setSecurity', 3, 'authPassphrase');
        }
        if ($argc >= 5) {
            $privacyProtocol = $this->stringArg($frame->calledArgs[4], 'SNMP::setSecurity', 4, 'privacyProtocol');
        }
        if ($argc >= 6) {
            $privacyPassphrase = $this->stringArg($frame->calledArgs[5], 'SNMP::setSecurity', 5, 'privacyPassphrase');
        }
        if ($argc >= 7) {
            $contextName = $this->stringArg($frame->calledArgs[6], 'SNMP::setSecurity', 6, 'contextName');
        }
        if ($argc >= 8) {
            $contextEngineId = $this->stringArg($frame->calledArgs[7], 'SNMP::setSecurity', 7, 'contextEngineId');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmSnmp::setSecurity(
            $receiver,
            $securityLevel,
            $authProtocol,
            $authPassphrase,
            $privacyProtocol,
            $privacyPassphrase,
            $contextName,
            $contextEngineId
        );
        $frame->returnVar->bool($ok);
    }
}
