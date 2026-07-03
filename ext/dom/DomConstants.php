<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/** DOM node type constants (php-src ext/dom/dom_ce.h; issue #6140). */
final class DomConstants
{
    public const XML_ELEMENT_NODE = 1;

    public const XML_TEXT_NODE = 3;

    public const XML_ATTRIBUTE_NODE = 2;

    public const XML_DOCUMENT_NODE = 9;

    public const XML_DOCUMENT_TYPE_NODE = 10;

    public const XML_DOCUMENT_FRAG_NODE = 11;

    /** Internal marker for {@see VmDom::createNodeList()} handles. */
    public const XML_NODELIST = -1;

    /** DOMNode::compareDocumentPosition() flags (php-src ext/dom/node.c; #14448). */
    public const DOCUMENT_POSITION_DISCONNECTED = 0x01;

    public const DOCUMENT_POSITION_PRECEDING = 0x02;

    public const DOCUMENT_POSITION_FOLLOWING = 0x04;

    public const DOCUMENT_POSITION_CONTAINS = 0x08;

    public const DOCUMENT_POSITION_CONTAINED_BY = 0x10;

    public const DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC = 0x20;
}
