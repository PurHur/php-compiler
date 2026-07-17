<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/** SOAP constants (php-src ext/soap/soap.stub.php / php_encoding.h; #20037, #20125). */
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

    // php-src ext/soap/php_encoding.h — core XSD / SOAP_ENC type ids for SoapVar.
    public const XSD_STRING = 101;
    public const XSD_BOOLEAN = 102;
    public const XSD_DECIMAL = 103;
    public const XSD_FLOAT = 104;
    public const XSD_DOUBLE = 105;
    public const XSD_DATETIME = 107;
    public const XSD_TIME = 108;
    public const XSD_DATE = 109;
    public const XSD_HEXBINARY = 115;
    public const XSD_BASE64BINARY = 116;
    public const XSD_ANYURI = 117;
    public const XSD_QNAME = 118;
    public const XSD_INTEGER = 131;
    public const XSD_LONG = 134;
    public const XSD_INT = 135;
    public const XSD_SHORT = 136;
    public const XSD_BYTE = 137;
    public const XSD_ANYTYPE = 145;
    public const XSD_ANYXML = 147;
    public const SOAP_ENC_ARRAY = 300;
    public const SOAP_ENC_OBJECT = 301;
    public const UNKNOWN_TYPE = 999998;

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
            'XSD_STRING' => self::XSD_STRING,
            'XSD_BOOLEAN' => self::XSD_BOOLEAN,
            'XSD_DECIMAL' => self::XSD_DECIMAL,
            'XSD_FLOAT' => self::XSD_FLOAT,
            'XSD_DOUBLE' => self::XSD_DOUBLE,
            'XSD_DATETIME' => self::XSD_DATETIME,
            'XSD_TIME' => self::XSD_TIME,
            'XSD_DATE' => self::XSD_DATE,
            'XSD_HEXBINARY' => self::XSD_HEXBINARY,
            'XSD_BASE64BINARY' => self::XSD_BASE64BINARY,
            'XSD_ANYURI' => self::XSD_ANYURI,
            'XSD_QNAME' => self::XSD_QNAME,
            'XSD_INTEGER' => self::XSD_INTEGER,
            'XSD_LONG' => self::XSD_LONG,
            'XSD_INT' => self::XSD_INT,
            'XSD_SHORT' => self::XSD_SHORT,
            'XSD_BYTE' => self::XSD_BYTE,
            'XSD_ANYTYPE' => self::XSD_ANYTYPE,
            'XSD_ANYXML' => self::XSD_ANYXML,
            'SOAP_ENC_ARRAY' => self::SOAP_ENC_ARRAY,
            'SOAP_ENC_OBJECT' => self::SOAP_ENC_OBJECT,
            'UNKNOWN_TYPE' => self::UNKNOWN_TYPE,
        ];
    }
}
