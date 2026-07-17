<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/** SOAP constants (php-src ext/soap/soap.stub.php; #20037). */
final class SoapConstants
{
    public const SOAP_1_1 = 1;
    public const SOAP_1_2 = 2;
    public const SOAP_DOCUMENT = 1;
    public const SOAP_RPC = 2;
    public const SOAP_ENCODED = 1;
    public const SOAP_LITERAL = 2;
    public const SOAP_ACTOR_NEXT = 1;
    public const SOAP_ACTOR_NONE = 2;
    public const SOAP_ACTOR_UNLIMATED = 3;
    public const SOAP_PERSISTENCE_SESSION = 1;
    public const SOAP_PERSISTENCE_REQUEST = 2;
    public const SOAP_FUNCTIONS_ALL = 999;
    public const SOAP_WAIT_ONE_WAY_CALLS = 0x10;
    public const SOAP_SINGLE_ELEMENT_ARRAYS = 1;
    public const SOAP_USE_XSI_ARRAY_TYPE = 2;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'SOAP_1_1' => self::SOAP_1_1,
            'SOAP_1_2' => self::SOAP_1_2,
            'SOAP_DOCUMENT' => self::SOAP_DOCUMENT,
            'SOAP_RPC' => self::SOAP_RPC,
            'SOAP_ENCODED' => self::SOAP_ENCODED,
            'SOAP_LITERAL' => self::SOAP_LITERAL,
            'SOAP_ACTOR_NEXT' => self::SOAP_ACTOR_NEXT,
            'SOAP_ACTOR_NONE' => self::SOAP_ACTOR_NONE,
            'SOAP_ACTOR_UNLIMATED' => self::SOAP_ACTOR_UNLIMATED,
            'SOAP_PERSISTENCE_SESSION' => self::SOAP_PERSISTENCE_SESSION,
            'SOAP_PERSISTENCE_REQUEST' => self::SOAP_PERSISTENCE_REQUEST,
            'SOAP_FUNCTIONS_ALL' => self::SOAP_FUNCTIONS_ALL,
            'SOAP_WAIT_ONE_WAY_CALLS' => self::SOAP_WAIT_ONE_WAY_CALLS,
            'SOAP_SINGLE_ELEMENT_ARRAYS' => self::SOAP_SINGLE_ELEMENT_ARRAYS,
            'SOAP_USE_XSI_ARRAY_TYPE' => self::SOAP_USE_XSI_ARRAY_TYPE,
        ];
    }
}
