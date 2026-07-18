<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/** DOM node type constants (php-src ext/dom/dom_ce.h; issue #6140). */
final class DomConstants
{
    public const XML_ELEMENT_NODE = 1;

    public const XML_TEXT_NODE = 3;

    public const XML_CDATA_SECTION_NODE = 4;

    public const XML_COMMENT_NODE = 8;

    public const XML_ATTRIBUTE_NODE = 2;

    public const XML_ENTITY_REF_NODE = 5;

    /** General entity declaration in doctype (php-src XML_ENTITY_DECL_NODE; #6320). */
    public const XML_ENTITY_DECL_NODE = 17;

    public const XML_DOCUMENT_NODE = 9;

    public const XML_DOCUMENT_TYPE_NODE = 10;

    public const XML_PROCESSING_INSTRUCTION_NODE = 7;

    public const XML_DOCUMENT_FRAG_NODE = 11;

    public const XML_NOTATION_NODE = 12;

    /** Namespace declaration node (libxml XML_NAMESPACE_DECL; php-src DOMNameSpaceNode; #20097). */
    public const XML_NAMESPACE_DECL_NODE = 18;

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
}
