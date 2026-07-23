<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

/**
 * SNMP constants (php-src ext/snmp/php_snmp.h / snmp.stub.php; #6070).
 */
final class SnmpConstants
{
    public const VERSION_1 = 0;
    public const VERSION_2C = 1;
    public const VERSION_2 = 1;
    public const VERSION_3 = 3;

    public const ERRNO_NOERROR = 0;
    public const ERRNO_GENERIC = 2;

    /** php-src SNMP_VALUE_* (php_snmp.h). */
    public const VALUE_LIBRARY = 0;
    public const VALUE_PLAIN = 1;
    public const VALUE_OBJECT = 2;

    /** php-src SNMP_OID_OUTPUT_* defaults used by helpers. */
    public const OID_OUTPUT_SUFFIX = 1;
    public const OID_OUTPUT_MODULE = 2;
    public const OID_OUTPUT_FULL = 3;
    public const OID_OUTPUT_NUMERIC = 4;
    public const OID_OUTPUT_UCD = 5;
    public const OID_OUTPUT_NONE = 6;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'version_1' => self::VERSION_1,
        'version_2c' => self::VERSION_2C,
        'version_2' => self::VERSION_2,
        'version_3' => self::VERSION_3,
        'errno_noerror' => self::ERRNO_NOERROR,
        'errno_generic' => self::ERRNO_GENERIC,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'version_1' => 'VERSION_1',
        'version_2c' => 'VERSION_2c',
        'version_2' => 'VERSION_2',
        'version_3' => 'VERSION_3',
        'errno_noerror' => 'ERRNO_NOERROR',
        'errno_generic' => 'ERRNO_GENERIC',
    ];

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'SNMP_VERSION_1' => self::VERSION_1,
            'SNMP_VERSION_2c' => self::VERSION_2C,
            'SNMP_VERSION_2' => self::VERSION_2,
            'SNMP_VERSION_3' => self::VERSION_3,
            'SNMP_VALUE_LIBRARY' => self::VALUE_LIBRARY,
            'SNMP_VALUE_PLAIN' => self::VALUE_PLAIN,
            'SNMP_VALUE_OBJECT' => self::VALUE_OBJECT,
            'SNMP_OID_OUTPUT_SUFFIX' => self::OID_OUTPUT_SUFFIX,
            'SNMP_OID_OUTPUT_MODULE' => self::OID_OUTPUT_MODULE,
            'SNMP_OID_OUTPUT_FULL' => self::OID_OUTPUT_FULL,
            'SNMP_OID_OUTPUT_NUMERIC' => self::OID_OUTPUT_NUMERIC,
            'SNMP_OID_OUTPUT_UCD' => self::OID_OUTPUT_UCD,
            'SNMP_OID_OUTPUT_NONE' => self::OID_OUTPUT_NONE,
        ];
    }
}