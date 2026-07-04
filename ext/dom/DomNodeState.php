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

    public ?string $localName = null;

    /** Empty string when unprefixed (php-src dom_object prefix). */
    public ?string $prefix = null;

    public ?string $namespaceUri = null;

    /**
     * xmlns / xmlns:prefix declarations on this element (php-src dom_namespace_decl).
     *
     * @var array<string, string>
     */
    public array $namespaceDeclarations = [];

    public ?string $publicId = null;

    public ?string $systemId = null;

    /** Root element local name for documents. */
    public ?string $documentElementName = null;

    /** Doctype fields copied into documents at createDocument() time. */
    public ?string $doctypeName = null;

    public ?string $doctypePublicId = null;

    public ?string $doctypeSystemId = null;

    /** Live DOMDocumentType child object id (php-src ext/dom/document.c; #15292). */
    public ?int $doctypeId = null;

    /** Child element object ids in document order (php-src dom_child_nodes). */
    /** @var list<int> */
    public array $childIds = [];

    /** Parent node object id, or null for detached roots. */
    public ?int $parentId = null;

    /** Owning document object id (php-src dom_object ownerDocument). */
    public ?int $documentId = null;

    /** Text node payload when {@see DomConstants::XML_TEXT_NODE}. */
    public ?string $textContent = null;

    /**
     * Member node ids when {@see DomConstants::XML_NODELIST}.
     *
     * @var list<int>
     */
    public array $listNodeIds = [];

    /** Iterator walk position for {@see DomConstants::XML_NODELIST} (php-src ext/dom/nodelist.c). */
    public int $listIterIndex = 0;

    /** Persistent childNodes list object id for element/document nodes. */
    public ?int $childNodesListId = null;

    /** Persistent attributes map object id for element nodes (php-src ext/dom/namednodemap.c). */
    public ?int $attributesListId = null;

    /** @var array<string, string> */
    public array $attributes = [];

    /**
     * Explicit namespace URI per attribute qName from setAttributeNS() (php-src ext/dom/element.c; #15380).
     *
     * @var array<string, string>
     */
    public array $attributeNamespaces = [];

    /** Attribute local name registered via setIdAttribute() (php-src dom_object attr->id). */
    public ?string $idAttributeName = null;

    /**
     * Live DOMAttr object ids per attribute qName (php-src ext/dom/attr.c; #14455).
     *
     * @var array<string, int>
     */
    public array $attributeNodeIds = [];

    /** Owning element object id for {@see DomConstants::XML_ATTRIBUTE_NODE} (#14455). */
    public ?int $ownerElementId = null;

    /** @var array<string, string> */
    public array $idAttrByElement = [];

    /** @var array<string, int> */
    public array $elementIds = [];

    /** XML declaration metadata (php-src ext/dom/document.c; #14420). */
    public string $xmlVersion = '1.0';

    public ?string $encoding = null;

    public bool $xmlStandalone = false;

    public ?string $documentUri = null;

    /** libxml line number (php-src dom_node_line_no; #14407). */
    public int $lineNo = 1;

    /** True after DOMDocument::loadHTML() (php-src ext/dom/document.c; #14356). */
    public bool $isHtmlDocument = false;

    /**
     * Per-document custom node class map: base builtin lc => extended class lc (php-src dom_set_doc_classmap; #15334).
     *
     * @var array<string, string>
     */
    public array $nodeClassMap = [];
}
