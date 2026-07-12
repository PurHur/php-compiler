<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

/**
 * XMLReader node-type and parser-option constants (libxml2 xmlReaderTypes; php-src ext/xmlreader).
 *
 * @see https://github.com/php/php-src/blob/master/ext/xmlreader/php_xmlreader.stub.php
 */
final class XmlReaderConstants
{
    /** libxml2 xmlReaderTypes — must match Zend XMLReader::* values. */
    public const NONE = 0;
    public const ELEMENT = 1;
    public const ATTRIBUTE = 2;
    public const TEXT = 3;
    public const CDATA = 4;
    public const ENTITY_REF = 5;
    public const ENTITY = 6;
    public const PI = 7;
    public const COMMENT = 8;
    public const DOC = 9;
    public const DOC_TYPE = 10;
    public const DOC_FRAGMENT = 11;
    public const NOTATION = 12;
    public const WHITESPACE = 13;
    public const SIGNIFICANT_WHITESPACE = 14;
    public const END_ELEMENT = 15;
    public const END_ENTITY = 16;
    public const XML_DECLARATION = 17;

    public const LOADDTD = 1;
    public const DEFAULTATTRS = 2;
    public const VALIDATE = 3;
    public const SUBST_ENTITIES = 4;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'NONE' => self::NONE,
            'ELEMENT' => self::ELEMENT,
            'ATTRIBUTE' => self::ATTRIBUTE,
            'TEXT' => self::TEXT,
            'CDATA' => self::CDATA,
            'ENTITY_REF' => self::ENTITY_REF,
            'ENTITY' => self::ENTITY,
            'PI' => self::PI,
            'COMMENT' => self::COMMENT,
            'DOC' => self::DOC,
            'DOC_TYPE' => self::DOC_TYPE,
            'DOC_FRAGMENT' => self::DOC_FRAGMENT,
            'NOTATION' => self::NOTATION,
            'WHITESPACE' => self::WHITESPACE,
            'SIGNIFICANT_WHITESPACE' => self::SIGNIFICANT_WHITESPACE,
            'END_ELEMENT' => self::END_ELEMENT,
            'END_ENTITY' => self::END_ENTITY,
            'XML_DECLARATION' => self::XML_DECLARATION,
            'LOADDTD' => self::LOADDTD,
            'DEFAULTATTRS' => self::DEFAULTATTRS,
            'VALIDATE' => self::VALIDATE,
            'SUBST_ENTITIES' => self::SUBST_ENTITIES,
        ];
    }
}
