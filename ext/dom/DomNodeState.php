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

    /** Expanded value for {@see DomConstants::XML_ENTITY_REF_NODE} textContent (php-src ext/dom; #6320). */
    public ?string $entityReplacementText = null;

    /** Unparsed entity notation name on {@see DomConstants::XML_ENTITY_DECL_NODE}. */
    public ?string $notationName = null;

    /** Live entities {@see DOMNamedNodeMap} object id on doctype nodes (#6320). */
    public ?int $entitiesMapId = null;

    /** Live notations {@see DOMNamedNodeMap} object id on doctype nodes (#6320). */
    public ?int $notationsMapId = null;

    /**
     * General entity replacement text by name for the owning document (#6320).
     *
     * @var array<string, string>
     */
    public array $generalEntities = [];

    /**
     * Member node ids when {@see DomConstants::XML_NODELIST}.
     *
     * @var list<int>
     */
    public array $listNodeIds = [];

    /** Iterator walk position for {@see DomConstants::XML_NODELIST} (php-src ext/dom/nodelist.c). */
    public int $listIterIndex = 0;

    /**
     * Live {@see DOMNodeList} query root for getElementsByTagName* (php-src ext/dom/nodelist.c).
     */
    public ?int $listQueryRootId = null;

    public ?string $listQueryTagName = null;

    public ?string $listQueryNamespaceUri = null;

    public ?string $listQueryLocalName = null;

    /** Persistent childNodes list object id for element/document nodes. */
    public ?int $childNodesListId = null;

    /** Persistent attributes map object id for element nodes (php-src ext/dom/namednodemap.c). */
    public ?int $attributesListId = null;

    /** Persistent DOMTokenList object id for element nodes (php-src ext/dom/token_list.c; #16876). */
    public ?int $classListId = null;

    /** Owning element object id for {@see DomConstants::XML_TOKENLIST} handles (#16876). */
    public ?int $tokenListElementId = null;

    /** Owning document object id for {@see DomConstants::XML_XPATH} handles (#6066). */
    public ?int $xpathDocumentId = null;

    /**
     * Registered namespace prefixes for {@see DomConstants::XML_XPATH} (#6066).
     *
     * @var array<string, string>
     */
    public array $xpathNamespaces = [];

    /**
     * Ordered unique tokens for {@see DomConstants::XML_TOKENLIST} (#16876).
     *
     * @var list<string>
     */
    public array $tokenListTokens = [];

    /** Cached `class` attribute for token-list staleness detection (#16876). */
    public ?string $tokenListCachedClassValue = null;

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

    /** True after DOMDocument::loadXML() — saveHTML expands empty elements (re-#18618, ext/dom/php_dom.c). */
    public bool $loadedViaXml = false;

    /** Original XML passed to loadXML() for libxml DTD validation (#18833). */
    public ?string $sourceXml = null;

    /**
     * Per-document custom node class map: base builtin lc => extended class lc (php-src dom_set_doc_classmap; #15334).
     *
     * @var array<string, string>
     */
    public array $nodeClassMap = [];
}
