<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\CompilerVersion;

/**
 * xml extension constants (php-src ext/xml/xml.c; #17799, #28171).
 */
final class XmlConstants
{
    public const XML_ERROR_NONE = 0;
    public const XML_ERROR_NO_MEMORY = 1;
    public const XML_ERROR_SYNTAX = 2;
    public const XML_ERROR_NO_ELEMENTS = 3;
    public const XML_ERROR_INVALID_TOKEN = 4;
    public const XML_ERROR_UNCLOSED_TOKEN = 5;
    public const XML_ERROR_PARTIAL_CHAR = 6;
    public const XML_ERROR_TAG_MISMATCH = 7;
    public const XML_ERROR_DUPLICATE_ATTRIBUTE = 8;
    public const XML_ERROR_JUNK_AFTER_DOC_ELEMENT = 9;
    public const XML_ERROR_PARAM_ENTITY_REF = 10;
    public const XML_ERROR_UNDEFINED_ENTITY = 11;
    public const XML_ERROR_RECURSIVE_ENTITY_REF = 12;
    public const XML_ERROR_ASYNC_ENTITY = 13;
    public const XML_ERROR_BAD_CHAR_REF = 14;
    public const XML_ERROR_BINARY_ENTITY_REF = 15;
    public const XML_ERROR_ATTRIBUTE_EXTERNAL_ENTITY_REF = 16;
    public const XML_ERROR_MISPLACED_XML_PI = 17;
    public const XML_ERROR_UNKNOWN_ENCODING = 18;
    public const XML_ERROR_INCORRECT_ENCODING = 19;
    public const XML_ERROR_UNCLOSED_CDATA_SECTION = 20;
    public const XML_ERROR_EXTERNAL_ENTITY_HANDLING = 21;
    public const XML_OPTION_CASE_FOLDING = 1;
    public const XML_OPTION_TARGET_ENCODING = 2;
    public const XML_OPTION_SKIP_TAGSTART = 3;
    public const XML_OPTION_SKIP_WHITE = 4;
    /** PHP 8.4+ — php-src PHP_XML_OPTION_PARSE_HUGE (#28171). */
    public const XML_OPTION_PARSE_HUGE = 5;

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        $constants = [
            'XML_ERROR_NONE' => self::XML_ERROR_NONE,
            'XML_ERROR_NO_MEMORY' => self::XML_ERROR_NO_MEMORY,
            'XML_ERROR_SYNTAX' => self::XML_ERROR_SYNTAX,
            'XML_ERROR_NO_ELEMENTS' => self::XML_ERROR_NO_ELEMENTS,
            'XML_ERROR_INVALID_TOKEN' => self::XML_ERROR_INVALID_TOKEN,
            'XML_ERROR_UNCLOSED_TOKEN' => self::XML_ERROR_UNCLOSED_TOKEN,
            'XML_ERROR_PARTIAL_CHAR' => self::XML_ERROR_PARTIAL_CHAR,
            'XML_ERROR_TAG_MISMATCH' => self::XML_ERROR_TAG_MISMATCH,
            'XML_ERROR_DUPLICATE_ATTRIBUTE' => self::XML_ERROR_DUPLICATE_ATTRIBUTE,
            'XML_ERROR_JUNK_AFTER_DOC_ELEMENT' => self::XML_ERROR_JUNK_AFTER_DOC_ELEMENT,
            'XML_ERROR_PARAM_ENTITY_REF' => self::XML_ERROR_PARAM_ENTITY_REF,
            'XML_ERROR_UNDEFINED_ENTITY' => self::XML_ERROR_UNDEFINED_ENTITY,
            'XML_ERROR_RECURSIVE_ENTITY_REF' => self::XML_ERROR_RECURSIVE_ENTITY_REF,
            'XML_ERROR_ASYNC_ENTITY' => self::XML_ERROR_ASYNC_ENTITY,
            'XML_ERROR_BAD_CHAR_REF' => self::XML_ERROR_BAD_CHAR_REF,
            'XML_ERROR_BINARY_ENTITY_REF' => self::XML_ERROR_BINARY_ENTITY_REF,
            'XML_ERROR_ATTRIBUTE_EXTERNAL_ENTITY_REF' => self::XML_ERROR_ATTRIBUTE_EXTERNAL_ENTITY_REF,
            'XML_ERROR_MISPLACED_XML_PI' => self::XML_ERROR_MISPLACED_XML_PI,
            'XML_ERROR_UNKNOWN_ENCODING' => self::XML_ERROR_UNKNOWN_ENCODING,
            'XML_ERROR_INCORRECT_ENCODING' => self::XML_ERROR_INCORRECT_ENCODING,
            'XML_ERROR_UNCLOSED_CDATA_SECTION' => self::XML_ERROR_UNCLOSED_CDATA_SECTION,
            'XML_ERROR_EXTERNAL_ENTITY_HANDLING' => self::XML_ERROR_EXTERNAL_ENTITY_HANDLING,
            'XML_OPTION_CASE_FOLDING' => self::XML_OPTION_CASE_FOLDING,
            'XML_OPTION_TARGET_ENCODING' => self::XML_OPTION_TARGET_ENCODING,
            'XML_OPTION_SKIP_TAGSTART' => self::XML_OPTION_SKIP_TAGSTART,
            'XML_OPTION_SKIP_WHITE' => self::XML_OPTION_SKIP_WHITE,
            'XML_SAX_IMPL' => 'libxml',
        ];
        // PHP 8.4+ only — absent from php-src ≤8.3 stubs (#28171).
        if (CompilerVersion::supportsXmlOptionParseHuge()) {
            $constants['XML_OPTION_PARSE_HUGE'] = self::XML_OPTION_PARSE_HUGE;
        }

        return $constants;
    }
}
