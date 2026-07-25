<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * DOM node type constants (php-src ext/dom/dom_ce.h, php_dom.c; issues #6140, #23138).
 *
 * Global PHP names match php-src REGISTER_LONG_CONSTANT (XML_PI_NODE for PI).
 */
final class DomConstants
{
    public const XML_ELEMENT_NODE = 1;

    public const XML_ATTRIBUTE_NODE = 2;

    public const XML_TEXT_NODE = 3;

    public const XML_CDATA_SECTION_NODE = 4;

    public const XML_ENTITY_REF_NODE = 5;

    public const XML_ENTITY_NODE = 6;

    public const XML_PROCESSING_INSTRUCTION_NODE = 7;

    public const XML_COMMENT_NODE = 8;

    public const XML_DOCUMENT_NODE = 9;

    public const XML_DOCUMENT_TYPE_NODE = 10;

    public const XML_DOCUMENT_FRAG_NODE = 11;

    public const XML_NOTATION_NODE = 12;

    public const XML_HTML_DOCUMENT_NODE = 13;

    public const XML_DTD_NODE = 14;

    public const XML_ELEMENT_DECL_NODE = 15;

    public const XML_ATTRIBUTE_DECL_NODE = 16;

    /** General entity declaration in doctype (php-src XML_ENTITY_DECL_NODE; #6320). */
    public const XML_ENTITY_DECL_NODE = 17;

    /** Namespace declaration node (libxml XML_NAMESPACE_DECL; php-src DOMNameSpaceNode; #20097). */
    public const XML_NAMESPACE_DECL_NODE = 18;

    /**
     * php-src DOM_PHP_ERR (0) — internal/generic DOM error; registered as global (#23138).
     */
    public const DOM_PHP_ERR = 0;

    /** Built-in xml prefix namespace URI (http://www.w3.org/XML/1998/namespace). */
    public const XML_NS_URI = 'http://www.w3.org/XML/1998/namespace';

    /** XInclude 1.0 namespace (libxml XINCLUDE_NS; php-src ext/dom/document.c). */
    public const XINCLUDE_NS = 'http://www.w3.org/2001/XInclude';

    /** Legacy XInclude namespace (libxml XINCLUDE_OLD_NS). */
    public const XINCLUDE_OLD_NS = 'http://www.w3.org/2003/XInclude';

    /** Internal marker for {@see VmDom::createNodeList()} handles. */
    public const XML_NODELIST = -1;

    /** Internal marker for {@see VmDom::createNamedNodeMap()} handles. */
    public const XML_NAMEDNODEMAP = -2;

    /** Internal marker for {@see VmDom::createTokenList()} handles (#16876). */
    public const XML_TOKENLIST = -3;

    /** Internal marker for {@see VmDom::createXPath()} handles (#6066). */
    public const XML_XPATH = -4;

    /** Namespace URI for php:function() / php:functionString() (#19331, php-src xpath.c). */
    public const PHP_XPATH_NS = 'http://php.net/xpath';

    /** php_dom_xpath_callback_ns::mode — none registered (#19331). */
    public const XPATH_REG_FUNC_MODE_NONE = 0;

    /** Allow any PHP callable via php:function() (#19331). */
    public const XPATH_REG_FUNC_MODE_ALL = 1;

    /** Allow only explicitly registered handler names (#19331). */
    public const XPATH_REG_FUNC_MODE_SET = 2;

    /** DOMNode::compareDocumentPosition() flags (php-src ext/dom/node.c; #14448). */
    public const DOCUMENT_POSITION_DISCONNECTED = 0x01;

    public const DOCUMENT_POSITION_PRECEDING = 0x02;

    public const DOCUMENT_POSITION_FOLLOWING = 0x04;

    public const DOCUMENT_POSITION_CONTAINS = 0x08;

    public const DOCUMENT_POSITION_CONTAINED_BY = 0x10;

    public const DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC = 0x20;

    /**
     * Libxml node-type globals + DOM_PHP_ERR (php-src ext/dom/php_dom.c; #23138).
     *
     * @return array<string, int>
     */
    public static function globalConstants(): array
    {
        return [
            'XML_ELEMENT_NODE' => self::XML_ELEMENT_NODE,
            'XML_ATTRIBUTE_NODE' => self::XML_ATTRIBUTE_NODE,
            'XML_TEXT_NODE' => self::XML_TEXT_NODE,
            'XML_CDATA_SECTION_NODE' => self::XML_CDATA_SECTION_NODE,
            'XML_ENTITY_REF_NODE' => self::XML_ENTITY_REF_NODE,
            'XML_ENTITY_NODE' => self::XML_ENTITY_NODE,
            // php-src global name is XML_PI_NODE (not XML_PROCESSING_INSTRUCTION_NODE).
            'XML_PI_NODE' => self::XML_PROCESSING_INSTRUCTION_NODE,
            'XML_COMMENT_NODE' => self::XML_COMMENT_NODE,
            'XML_DOCUMENT_NODE' => self::XML_DOCUMENT_NODE,
            'XML_DOCUMENT_TYPE_NODE' => self::XML_DOCUMENT_TYPE_NODE,
            'XML_DOCUMENT_FRAG_NODE' => self::XML_DOCUMENT_FRAG_NODE,
            'XML_NOTATION_NODE' => self::XML_NOTATION_NODE,
            'XML_HTML_DOCUMENT_NODE' => self::XML_HTML_DOCUMENT_NODE,
            'XML_DTD_NODE' => self::XML_DTD_NODE,
            'XML_ELEMENT_DECL_NODE' => self::XML_ELEMENT_DECL_NODE,
            'XML_ATTRIBUTE_DECL_NODE' => self::XML_ATTRIBUTE_DECL_NODE,
            'XML_ENTITY_DECL_NODE' => self::XML_ENTITY_DECL_NODE,
            'XML_NAMESPACE_DECL_NODE' => self::XML_NAMESPACE_DECL_NODE,
            // Alias of XML_NAMESPACE_DECL_NODE (php-src REGISTER_LONG_CONSTANT).
            'XML_LOCAL_NAMESPACE' => self::XML_NAMESPACE_DECL_NODE,
            'DOM_PHP_ERR' => self::DOM_PHP_ERR,
        ];
    }

    public static function registerGlobals(Context $ctx): void
    {
        foreach (self::globalConstants() as $name => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ctx->defineConstant($name, $var);
        }
    }
}
