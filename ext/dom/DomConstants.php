<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/** DOM node type constants (php-src ext/dom/dom_ce.h; issue #6140). */
final class DomConstants
{
    public const XML_ELEMENT_NODE = 1;

    public const XML_TEXT_NODE = 3;

    public const XML_DOCUMENT_NODE = 9;

    public const XML_DOCUMENT_TYPE_NODE = 10;

    public const XML_DOCUMENT_FRAG_NODE = 11;

    /** Internal marker for {@see VmDom::createNodeList()} handles. */
    public const XML_NODELIST = -1;
}
