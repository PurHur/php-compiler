<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

/** Per-SNMP-object session state (php-src php_snmp_object; #6070). */
final class SnmpState
{
    public int $version = SnmpConstants::VERSION_1;
    public string $hostname = '';
    public string $community = '';
    public int $timeout = -1;
    public int $retries = -1;
    public bool $closed = false;
    public int $errno = SnmpConstants::ERRNO_NOERROR;
    public string $error = '';
}