<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Internal DOM node state keyed by VM object id (php-src ext/dom/php_dom.c; issue #6140).
 */
final class DomNodeState
{
    public int $nodeType;

    public string $nodeName;

    public ?string $namespaceUri = null;

    public ?string $publicId = null;

    public ?string $systemId = null;

    /** Root element local name for documents. */
    public ?string $documentElementName = null;

    /** Doctype fields copied into documents at createDocument() time. */
    public ?string $doctypeName = null;

    public ?string $doctypePublicId = null;

    public ?string $doctypeSystemId = null;

    /** Child element object ids in document order (php-src dom_child_nodes). */
    /** @var list<int> */
    public array $childIds = [];

    /** Parent node object id, or null for detached roots. */
    public ?int $parentId = null;

    /**
     * Member node ids when {@see DomConstants::XML_NODELIST}.
     *
     * @var list<int>
     */
    public array $listNodeIds = [];

    /** Persistent childNodes list object id for element/document nodes. */
    public ?int $childNodesListId = null;
}
