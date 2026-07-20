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
        ];
    }
}