<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Variable;

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
     * Includes createElementNS nsDef synthetics used for saveXml; see
     * {@see $xmlnsAttributePrefixes} for attribute-sourced decls only (#20924).
     *
     * @var array<string, string>
     */
    public array $namespaceDeclarations = [];

    /**
     * Prefixes ('' = default) whose {@see $namespaceDeclarations} entry came from an
     * xmlns / xmlns:* attribute (parse or setAttributeNS) — not createElementNS nsDef.
     * Used by Dom\Element::getInScopeNamespaces() (php-src element.c; #20924).
     *
     * @var array<string, true>
     */
    public array $xmlnsAttributePrefixes = [];

    public ?string $publicId = null;

    public ?string $systemId = null;

    /**
     * Serialized DTD internal subset (libxml intSubset dump), or null when absent (#21000).
     */
    public ?string $internalSubset = null;

    /** Root element local name for documents. */
    public ?string $documentElementName = null;

    /** Doctype fields copied into documents at createDocument() time. */
    public ?string $doctypeName = null;

    public ?string $doctypePublicId = null;

    public ?string $doctypeSystemId = null;

    /** Live DOMDocumentType child object id (php-src ext/dom/document.c; #15292). */
    public ?int $doctypeId = null;

    /**
     * User-visible handles retained this wrapper (php-src dom_object refcount; #23817).
     * Incremented when a CV/global receives the node via ASSIGN; never decremented.
     */
    public int $userHandleCount = 0;

    /**
     * Underlying node was freed by dom_remove_all_children / textContent write
     * (php-src php_libxml_node_free_list + dom_objects_not_found; #23817).
     */
    public bool $nodeFreed = false;

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

    /** Live {@see DOMNodeList}/{@see Dom\HTMLCollection} class-token query for getElementsByClassName (#20556, #20709). */
    public ?string $listQueryClassNames = null;

    /** Most recent childNodes list object id for element/document nodes. */
    public ?int $childNodesListId = null;

    /**
     * All childNodes {@see DOMNodeList} wrappers issued for this node (Zend distinct
     * wrappers per property read; php-src ext/dom/node.c; #26330).
     *
     * @var list<int>
     */
    public array $liveChildNodesListIds = [];

    /**
     * Persistent Dom\HTMLCollection id for ParentNode::$children (element children only; #21033).
     */
    public ?int $childrenListId = null;

    /** Most recent attributes map object id for element nodes (php-src ext/dom/namednodemap.c). */
    public ?int $attributesListId = null;

    /**
     * All attributes {@see DOMNamedNodeMap} wrappers issued for this element (Zend distinct
     * wrappers per property read; php-src ext/dom/php_dom.c; #26330).
     *
     * @var list<int>
     */
    public array $liveAttributesMapIds = [];

    /** Persistent Dom\TokenList object id for living element nodes (php-src ext/dom/token_list.c; #16876, #28227). */
    public ?int $classListId = null;

    /** Owning element object id for {@see DomConstants::XML_TOKENLIST} handles (#16876). */
    public ?int $tokenListElementId = null;

    /** Owning document object id for {@see DomConstants::XML_XPATH} handles (#6066). */
    public ?int $xpathDocumentId = null;

    /**
     * DOMXPath::$registerNodeNamespaces — auto-register in-scope prefixes (php-src xpath.c; #20842).
     *
     * Default true matches {@see DOMXPath::__construct} `$registerNodeNS = true`.
     */
    public bool $xpathRegisterNodeNamespaces = true;

    /**
     * Registered namespace prefixes for {@see DomConstants::XML_XPATH} (#6066).
     *
     * @var array<string, string>
     */
    public array $xpathNamespaces = [];

    /**
     * PHP function allowlist mode for {@see DomConstants::XML_XPATH} (#19331).
     *
     * php-src: ext/dom/xpath_callbacks.c — PHP_DOM_REG_FUNC_MODE_{NONE,ALL,SET}
     * 0 = none, 1 = all, 2 = explicit set in {@see $xpathPhpFunctions}.
     */
    public int $xpathPhpFunctionsMode = 0;

    /**
     * Explicit php:function() handler names when mode is SET (#19331).
     *
     * @var array<string, true>
     */
    public array $xpathPhpFunctions = [];

    /**
     * Namespaced XPath PHP callables from registerPhpFunctionNS() (#20119).
     *
     * Map: namespace URI → local name → callable Variable (string / Closure / FCC).
     * php-src: ext/dom/xpath_callbacks.c — php_dom_xpath_callbacks.namespaces
     *
     * @var array<string, array<string, Variable>>
     */
    public array $xpathPhpFunctionNs = [];

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
     * libxml attr->atype == XML_ATTRIBUTE_ID markers (HTML id / xml:id).
     *
     * Copied by importNode/cloneNode like xmlCopyProp; distinct from {@see $idAttributeName}
     * which is setIdAttribute()-only and does not survive importNode (php-src / Zend).
     *
     * @var array<string, true>
     */
    public array $attributeIsId = [];

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
     * Temporary Dom\HTML_NO_DEFAULT_NS parse flag — omit default XHTML ns on createElement
     * during HTMLDocument load (php-src html_document.c; #26008). Cleared after loadHTML.
     */
    public bool $htmlNoDefaultNs = false;

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

    /**
     * DocumentFragment object id holding HTML `<template>` contents
     * (php-src php_dom_ensure_templated_content / private_data.c; #26034).
     *
     * Not listed in {@see $childIds} — child traversal on the template element stays empty.
     */
    public ?int $templateContentId = null;
}
