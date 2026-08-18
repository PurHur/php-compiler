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
    /** php-src php_soap.h — intentional misspelling of "unlimited" (#21621). */
    public const SOAP_ACTOR_UNLIMATERECEIVER = 3;
    public const SOAP_PERSISTENCE_SESSION = 1;
    public const SOAP_PERSISTENCE_REQUEST = 2;
    public const SOAP_FUNCTIONS_ALL = 999;
    public const SOAP_WAIT_ONE_WAY_CALLS = 0x10;
    public const SOAP_SINGLE_ELEMENT_ARRAYS = 1;
    public const SOAP_USE_XSI_ARRAY_TYPE = 2;

    // php-src ext/soap/php_soap.h (#20220)
    public const SOAP_COMPRESSION_ACCEPT = 0x20;
    public const SOAP_COMPRESSION_GZIP = 0x00;
    public const SOAP_COMPRESSION_DEFLATE = 0x10;
    public const SOAP_AUTHENTICATION_BASIC = 0;
    public const SOAP_AUTHENTICATION_DIGEST = 1;
    public const WSDL_CACHE_NONE = 0x0;
    public const WSDL_CACHE_DISK = 0x1;
    public const WSDL_CACHE_MEMORY = 0x2;
    public const WSDL_CACHE_BOTH = 0x3;

    // php-src ext/soap/php_soap.h — SOAP SSL method constants (#20295)
    public const SOAP_SSL_METHOD_TLS = 0;
    public const SOAP_SSL_METHOD_SSLv2 = 1;
    public const SOAP_SSL_METHOD_SSLv3 = 2;
    public const SOAP_SSL_METHOD_SSLv23 = 3;

    // php-src ext/soap/php_encoding.h — core XSD / SOAP_ENC type ids for SoapVar.
    public const XSD_STRING = 101;
    public const XSD_BOOLEAN = 102;
    public const XSD_DECIMAL = 103;
    public const XSD_FLOAT = 104;
    public const XSD_DOUBLE = 105;
    public const XSD_DURATION = 106;
    public const XSD_DATETIME = 107;
    public const XSD_TIME = 108;
    public const XSD_DATE = 109;
    public const XSD_GYEARMONTH = 110;
    public const XSD_GYEAR = 111;
    public const XSD_GMONTHDAY = 112;
    public const XSD_GDAY = 113;
    public const XSD_GMONTH = 114;
    public const XSD_HEXBINARY = 115;
    public const XSD_BASE64BINARY = 116;
    public const XSD_ANYURI = 117;
    public const XSD_QNAME = 118;
    public const XSD_NOTATION = 119;
    public const XSD_NORMALIZEDSTRING = 120;
    public const XSD_TOKEN = 121;
    public const XSD_LANGUAGE = 122;
    public const XSD_NMTOKEN = 123;
    public const XSD_NAME = 124;
    public const XSD_NCNAME = 125;
    public const XSD_ID = 126;
    public const XSD_IDREF = 127;
    public const XSD_IDREFS = 128;
    public const XSD_ENTITY = 129;
    public const XSD_ENTITIES = 130;
    public const XSD_INTEGER = 131;
    public const XSD_NONPOSITIVEINTEGER = 132;
    public const XSD_NEGATIVEINTEGER = 133;
    public const XSD_LONG = 134;
    public const XSD_INT = 135;
    public const XSD_SHORT = 136;
    public const XSD_BYTE = 137;
    public const XSD_NONNEGATIVEINTEGER = 138;
    public const XSD_UNSIGNEDLONG = 139;
    public const XSD_UNSIGNEDINT = 140;
    public const XSD_UNSIGNEDSHORT = 141;
    public const XSD_UNSIGNEDBYTE = 142;
    public const XSD_POSITIVEINTEGER = 143;
    public const XSD_NMTOKENS = 144;
    public const XSD_ANYTYPE = 145;
    public const XSD_ANYXML = 147;
    public const APACHE_MAP = 200;
    public const SOAP_ENC_ARRAY = 300;
    public const SOAP_ENC_OBJECT = 301;
    public const XSD_1999_TIMEINSTANT = 401;
    public const UNKNOWN_TYPE = 999998;

    /** php-src php_encoding.h string namespaces (#21624). */
    public const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';
    public const XSD_1999_NAMESPACE = 'http://www.w3.org/1999/XMLSchema';
    /** php-src php_soap.h SOAP_1_1_ENV_NAMESPACE / SOAP_1_2_ENV_NAMESPACE (#31956). */
    public const SOAP_1_1_ENV_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const SOAP_1_2_ENV_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';
    public const SOAP_1_1_ENV_NS_PREFIX = 'SOAP-ENV';
    public const SOAP_1_2_ENV_NS_PREFIX = 'env';
    /** php-src php_soap.h SOAP_1_1_ENC_NAMESPACE / SOAP_1_2_ENC_NAMESPACE (#31919). */
    public const SOAP_1_1_ENC_NAMESPACE = 'http://schemas.xmlsoap.org/soap/encoding/';
    public const SOAP_1_2_ENC_NAMESPACE = 'http://www.w3.org/2003/05/soap-encoding';
    /** php-src php_soap.h SOAP_*_ACTOR_* (#31920). */
    public const SOAP_1_1_ACTOR_NEXT = 'http://schemas.xmlsoap.org/soap/actor/next';
    public const SOAP_1_2_ACTOR_NEXT = 'http://www.w3.org/2003/05/soap-envelope/role/next';
    public const SOAP_1_2_ACTOR_NONE = 'http://www.w3.org/2003/05/soap-envelope/role/none';
    public const SOAP_1_2_ACTOR_UNLIMATERECEIVER = 'http://www.w3.org/2003/05/soap-envelope/role/ultimateReceiver';

    /** @return array<string, int|string> */
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
            'SOAP_ACTOR_UNLIMATERECEIVER' => self::SOAP_ACTOR_UNLIMATERECEIVER,
            'SOAP_PERSISTENCE_SESSION' => self::SOAP_PERSISTENCE_SESSION,
            'SOAP_PERSISTENCE_REQUEST' => self::SOAP_PERSISTENCE_REQUEST,
            'SOAP_FUNCTIONS_ALL' => self::SOAP_FUNCTIONS_ALL,
            'SOAP_WAIT_ONE_WAY_CALLS' => self::SOAP_WAIT_ONE_WAY_CALLS,
            'SOAP_SINGLE_ELEMENT_ARRAYS' => self::SOAP_SINGLE_ELEMENT_ARRAYS,
            'SOAP_USE_XSI_ARRAY_TYPE' => self::SOAP_USE_XSI_ARRAY_TYPE,
            'SOAP_COMPRESSION_ACCEPT' => self::SOAP_COMPRESSION_ACCEPT,
            'SOAP_COMPRESSION_GZIP' => self::SOAP_COMPRESSION_GZIP,
            'SOAP_COMPRESSION_DEFLATE' => self::SOAP_COMPRESSION_DEFLATE,
            'SOAP_AUTHENTICATION_BASIC' => self::SOAP_AUTHENTICATION_BASIC,
            'SOAP_AUTHENTICATION_DIGEST' => self::SOAP_AUTHENTICATION_DIGEST,
            'WSDL_CACHE_NONE' => self::WSDL_CACHE_NONE,
            'WSDL_CACHE_DISK' => self::WSDL_CACHE_DISK,
            'WSDL_CACHE_MEMORY' => self::WSDL_CACHE_MEMORY,
            'WSDL_CACHE_BOTH' => self::WSDL_CACHE_BOTH,
            'SOAP_SSL_METHOD_TLS' => self::SOAP_SSL_METHOD_TLS,
            'SOAP_SSL_METHOD_SSLv2' => self::SOAP_SSL_METHOD_SSLv2,
            'SOAP_SSL_METHOD_SSLv3' => self::SOAP_SSL_METHOD_SSLv3,
            'SOAP_SSL_METHOD_SSLv23' => self::SOAP_SSL_METHOD_SSLv23,
            'XSD_STRING' => self::XSD_STRING,
            'XSD_BOOLEAN' => self::XSD_BOOLEAN,
            'XSD_DECIMAL' => self::XSD_DECIMAL,
            'XSD_FLOAT' => self::XSD_FLOAT,
            'XSD_DOUBLE' => self::XSD_DOUBLE,
            'XSD_DURATION' => self::XSD_DURATION,
            'XSD_DATETIME' => self::XSD_DATETIME,
            'XSD_TIME' => self::XSD_TIME,
            'XSD_DATE' => self::XSD_DATE,
            'XSD_GYEARMONTH' => self::XSD_GYEARMONTH,
            'XSD_GYEAR' => self::XSD_GYEAR,
            'XSD_GMONTHDAY' => self::XSD_GMONTHDAY,
            'XSD_GDAY' => self::XSD_GDAY,
            'XSD_GMONTH' => self::XSD_GMONTH,
            'XSD_HEXBINARY' => self::XSD_HEXBINARY,
            'XSD_BASE64BINARY' => self::XSD_BASE64BINARY,
            'XSD_ANYURI' => self::XSD_ANYURI,
            'XSD_QNAME' => self::XSD_QNAME,
            'XSD_NOTATION' => self::XSD_NOTATION,
            'XSD_NORMALIZEDSTRING' => self::XSD_NORMALIZEDSTRING,
            'XSD_TOKEN' => self::XSD_TOKEN,
            'XSD_LANGUAGE' => self::XSD_LANGUAGE,
            'XSD_NMTOKEN' => self::XSD_NMTOKEN,
            'XSD_NAME' => self::XSD_NAME,
            'XSD_NCNAME' => self::XSD_NCNAME,
            'XSD_ID' => self::XSD_ID,
            'XSD_IDREF' => self::XSD_IDREF,
            'XSD_IDREFS' => self::XSD_IDREFS,
            'XSD_ENTITY' => self::XSD_ENTITY,
            'XSD_ENTITIES' => self::XSD_ENTITIES,
            'XSD_INTEGER' => self::XSD_INTEGER,
            'XSD_NONPOSITIVEINTEGER' => self::XSD_NONPOSITIVEINTEGER,
            'XSD_NEGATIVEINTEGER' => self::XSD_NEGATIVEINTEGER,
            'XSD_LONG' => self::XSD_LONG,
            'XSD_INT' => self::XSD_INT,
            'XSD_SHORT' => self::XSD_SHORT,
            'XSD_BYTE' => self::XSD_BYTE,
            'XSD_NONNEGATIVEINTEGER' => self::XSD_NONNEGATIVEINTEGER,
            'XSD_UNSIGNEDLONG' => self::XSD_UNSIGNEDLONG,
            'XSD_UNSIGNEDINT' => self::XSD_UNSIGNEDINT,
            'XSD_UNSIGNEDSHORT' => self::XSD_UNSIGNEDSHORT,
            'XSD_UNSIGNEDBYTE' => self::XSD_UNSIGNEDBYTE,
            'XSD_POSITIVEINTEGER' => self::XSD_POSITIVEINTEGER,
            'XSD_NMTOKENS' => self::XSD_NMTOKENS,
            'XSD_ANYTYPE' => self::XSD_ANYTYPE,
            'XSD_ANYXML' => self::XSD_ANYXML,
            'APACHE_MAP' => self::APACHE_MAP,
            'SOAP_ENC_ARRAY' => self::SOAP_ENC_ARRAY,
            'SOAP_ENC_OBJECT' => self::SOAP_ENC_OBJECT,
            'XSD_1999_TIMEINSTANT' => self::XSD_1999_TIMEINSTANT,
            'UNKNOWN_TYPE' => self::UNKNOWN_TYPE,
            'XSD_NAMESPACE' => self::XSD_NAMESPACE,
            'XSD_1999_NAMESPACE' => self::XSD_1999_NAMESPACE,
        ];
    }

    /**
     * php-src php_encoding.h type_str + ns prefix for encoded xsi:type (#32190).
     *
     * @return array{0: string, 1: string}|null [prefix, localName] e.g. ['xsd','string']
     */
    public static function soapEncTypeXsiQName(int $encType): ?array
    {
        $xsd = [
            self::XSD_STRING => 'string',
            self::XSD_BOOLEAN => 'boolean',
            self::XSD_DECIMAL => 'decimal',
            self::XSD_FLOAT => 'float',
            self::XSD_DOUBLE => 'double',
            self::XSD_DURATION => 'duration',
            self::XSD_DATETIME => 'dateTime',
            self::XSD_TIME => 'time',
            self::XSD_DATE => 'date',
            self::XSD_GYEARMONTH => 'gYearMonth',
            self::XSD_GYEAR => 'gYear',
            self::XSD_GMONTHDAY => 'gMonthDay',
            self::XSD_GDAY => 'gDay',
            self::XSD_GMONTH => 'gMonth',
            self::XSD_HEXBINARY => 'hexBinary',
            self::XSD_BASE64BINARY => 'base64Binary',
            self::XSD_ANYURI => 'anyURI',
            self::XSD_QNAME => 'QName',
            self::XSD_NOTATION => 'NOTATION',
            self::XSD_NORMALIZEDSTRING => 'normalizedString',
            self::XSD_TOKEN => 'token',
            self::XSD_LANGUAGE => 'language',
            self::XSD_NMTOKEN => 'NMTOKEN',
            self::XSD_NMTOKENS => 'NMTOKENS',
            self::XSD_NAME => 'Name',
            self::XSD_NCNAME => 'NCName',
            self::XSD_ID => 'ID',
            self::XSD_IDREF => 'IDREF',
            self::XSD_IDREFS => 'IDREFS',
            self::XSD_ENTITY => 'ENTITY',
            self::XSD_ENTITIES => 'ENTITIES',
            self::XSD_INTEGER => 'integer',
            self::XSD_NONPOSITIVEINTEGER => 'nonPositiveInteger',
            self::XSD_NEGATIVEINTEGER => 'negativeInteger',
            self::XSD_LONG => 'long',
            self::XSD_INT => 'int',
            self::XSD_SHORT => 'short',
            self::XSD_BYTE => 'byte',
            self::XSD_NONNEGATIVEINTEGER => 'nonNegativeInteger',
            self::XSD_UNSIGNEDLONG => 'unsignedLong',
            self::XSD_UNSIGNEDINT => 'unsignedInt',
            self::XSD_UNSIGNEDSHORT => 'unsignedShort',
            self::XSD_UNSIGNEDBYTE => 'unsignedByte',
            self::XSD_POSITIVEINTEGER => 'positiveInteger',
            self::XSD_ANYTYPE => 'anyType',
            self::XSD_1999_TIMEINSTANT => 'timeInstant',
        ];
        if (isset($xsd[$encType])) {
            return ['xsd', $xsd[$encType]];
        }
        if (self::SOAP_ENC_OBJECT === $encType) {
            return ['SOAP-ENC', 'Struct'];
        }
        if (self::SOAP_ENC_ARRAY === $encType) {
            return ['SOAP-ENC', 'Array'];
        }
        if (self::APACHE_MAP === $encType) {
            return ['apache', 'Map'];
        }

        return null;
    }
}
