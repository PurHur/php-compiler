<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PHP 8.4 Dom\ living-standard namespace (php-src ext/dom/html_document.c; #6506).
 *
 * Reuses {@see DomRegistry} tree state from legacy DOMDocument paths.
 */
final class VmDomLiving
{
    public const CLASS_NODE = 'dom\\node';

    public const CLASS_ELEMENT = 'dom\\element';

    public const CLASS_HTML_ELEMENT = 'dom\\htmlelement';

    /** php-src Dom\TokenList (ext/dom/token_list.c / php_dom.stub.php; #20512). */
    public const CLASS_TOKEN_LIST = 'dom\\tokenlist';

    /** php-src Dom\HTMLCollection (ext/dom/html_collection.c / php_dom.stub.php; #20709). */
    public const CLASS_HTML_COLLECTION = 'dom\\htmlcollection';

    /** php-src Dom\NodeList (php_dom.stub.php; XPath query/evaluate node-sets; #20757). */
    public const CLASS_NODE_LIST = 'dom\\nodelist';

    /** php-src Dom\XPath (php_dom.stub.php / xpath.c; #20757). */
    public const CLASS_XPATH = 'dom\\xpath';

    /** php-src Dom\AdjacentPosition (php_dom.stub.php; #20782). */
    public const CLASS_ADJACENT_POSITION = DomAdjacentPositionEnum::CLASS_LC;

    /** HTML namespace URI (https://html.spec.whatwg.org/#html-namespace). */
    public const HTML_NS = 'http://www.w3.org/1999/xhtml';

    public const CLASS_DOCUMENT = 'dom\\document';

    public const CLASS_HTML_DOCUMENT = 'dom\\htmldocument';

    public const CLASS_XML_DOCUMENT = 'dom\\xmldocument';

    public const PROP_BODY = 'body';

    public const PROP_HEAD = 'head';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    /** https://html.spec.whatwg.org/#document.title — php-src ext/dom/html_document.c (#19580). */
    public const PROP_TITLE = 'title';

    public static function isLivingDocument(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return self::CLASS_HTML_DOCUMENT === $lc || self::CLASS_XML_DOCUMENT === $lc;
    }

    public static function isLivingDocumentClass(string $classLc): bool
    {
        $lc = strtolower($classLc);

        return self::CLASS_HTML_DOCUMENT === $lc || self::CLASS_XML_DOCUMENT === $lc;
    }

    public static function isLivingElement(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return self::CLASS_ELEMENT === $lc || self::CLASS_HTML_ELEMENT === $lc;
    }

    public static function isLivingElementClass(string $classLc): bool
    {
        $lc = strtolower($classLc);

        return self::CLASS_ELEMENT === $lc || self::CLASS_HTML_ELEMENT === $lc;
    }

    public static function allocateHtmlDocument(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_HTML_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\HTMLDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmDom::ensureDocument($entry);
        VmDom::ensureChildNodesList($ctx, $entry);
        $state = DomRegistry::state($entry);
        $state->isHtmlDocument = true;
        self::applyLivingElementClassMap($state);

        return $entry;
    }

    public static function allocateXmlDocument(Context $ctx): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_XML_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\XMLDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmDom::ensureDocument($entry);
        VmDom::ensureChildNodesList($ctx, $entry);
        $state = DomRegistry::state($entry);
        $state->isHtmlDocument = false;
        self::applyLivingXmlElementClassMap($state);

        return $entry;
    }

    public static function createFromString(
        Context $ctx,
        string $source,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        self::assertValidHtmlParseOptions($options);
        if (null !== $overrideEncoding && '' === $overrideEncoding) {
            throw new \ValueError('Dom\\HTMLDocument::createFromString(): Argument #3 ($overrideEncoding) must not be empty');
        }

        $document = self::allocateHtmlDocument($ctx);
        if (null !== $overrideEncoding) {
            DomRegistry::state($document)->encoding = $overrideEncoding;
        }
        $ok = VmDom::loadHTML($ctx, $document, $source, $options, $frame);
        if (!$ok) {
            throw new \DOMException('Dom\\HTMLDocument::createFromString(): failed to parse HTML');
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    public static function createEmpty(Context $ctx, string $encoding = 'UTF-8'): Variable
    {
        if ('' === $encoding) {
            throw new \ValueError('Dom\\HTMLDocument::createEmpty(): Argument #1 ($encoding) must not be empty');
        }

        return self::createFromString(
            $ctx,
            '<!DOCTYPE html><html><head></head><body></body></html>',
            0,
            $encoding
        );
    }

    /**
     * Dom\HTMLDocument::createFromFile() — php-src ext/dom/html_document.c (#19580).
     */
    public static function createFromFile(
        Context $ctx,
        string $path,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        if ('' === $path) {
            throw new \ValueError('Dom\\HTMLDocument::createFromFile(): Argument #1 ($path) must not be empty');
        }
        self::assertValidHtmlParseOptions($options);
        if (null !== $overrideEncoding && '' === $overrideEncoding) {
            throw new \ValueError('Dom\\HTMLDocument::createFromFile(): Argument #3 ($overrideEncoding) must not be empty');
        }

        $contents = \PHPCompiler\ext\standard\VmFsReadNative::read($path);
        if (false === $contents) {
            throw new \DOMException(
                'Dom\\HTMLDocument::createFromFile(): failed to load external entity "'.$path.'"'
            );
        }

        return self::createFromString($ctx, $contents, $options, $overrideEncoding, $frame);
    }

    /**
     * Document.title getter — first in-tree HTML title element text (php-src html_document.c; #19580).
     */
    public static function htmlDocumentTitle(ObjectEntry $document): string
    {
        $title = self::htmlTitleElement($document);
        if (null === $title) {
            return '';
        }

        return VmDom::readTextContent($title);
    }

    /**
     * Document.title setter — php-src ext/dom/html_document.c dom_html_document_title_write (#19580).
     */
    public static function setHtmlDocumentTitle(Context $ctx, ObjectEntry $document, string $value): void
    {
        $title = self::htmlTitleElement($document);
        if (null === $title) {
            $head = self::htmlHeadElement($document);
            if (null === $head) {
                return;
            }
            $titleVar = VmDom::createElement($ctx, 'title', $document);
            $title = $titleVar->toObject();
            VmDom::appendChild($ctx, $head, $title);
        }
        VmDom::writeTextContent($ctx, $title, $value);
    }

    /**
     * First in-tree title element (HTML document order) — php-src dom_get_title_element (#19580).
     */
    public static function htmlTitleElement(ObjectEntry $document): ?ObjectEntry
    {
        $root = self::htmlRootElement($document);
        if (null === $root) {
            return null;
        }

        return self::findFirstElementByLocalName($root, 'title');
    }

    /**
     * Dom\Document::getElementById() — alias of classic ID map (php-src php_dom.stub.php; #19580).
     */
    public static function getElementById(ObjectEntry $document, string $elementId): ?ObjectEntry
    {
        return VmDom::getElementById($document, $elementId);
    }

    /**
     * ParentNode::querySelector() — minimal CSS subset for living DOM (#19580, #20689, #20866).
     *
     * Supports: `*`, tag, `#id`, `.class`, `:first-child`, `:last-child`,
     * space-separated descendants, and comma selector lists (CSS Selectors Level 3 /
     * php-src Dom\* lexbor).
     */
    public static function querySelector(ObjectEntry $root, string $selectors): ?ObjectEntry
    {
        $matches = self::querySelectorAllIds($root, $selectors);
        if ([] === $matches) {
            return null;
        }

        return DomRegistry::entry($matches[0]);
    }

    /**
     * @return list<int> matching element object ids in document order
     */
    public static function querySelectorAllIds(ObjectEntry $root, string $selectors): array
    {
        $selectors = trim($selectors);
        if ('' === $selectors) {
            throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
        }
        // CSS selector lists: "a, b" unions groups (php-src Dom\* / lexbor; #20689).
        $groups = preg_split('/\s*,\s*/', $selectors) ?: [];
        if ([] === $groups) {
            throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
        }
        $matchIds = [];
        foreach ($groups as $group) {
            $group = trim($group);
            if ('' === $group) {
                // Empty group ("p,,span" / trailing ",") — SyntaxError like lexbor.
                throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
            }
            foreach (self::querySelectorAllIdsOneGroup($root, $group) as $id) {
                $matchIds[$id] = true;
            }
        }

        // Preserve document order from a single tree walk.
        $ordered = [];
        $candidates = self::collectDescendantElements($root);
        if (VmDom::isElement($root)) {
            array_unshift($candidates, $root);
        }
        foreach ($candidates as $el) {
            if (isset($matchIds[$el->id])) {
                $ordered[] = $el->id;
            }
        }

        return $ordered;
    }

    /**
     * Match one selector group (no top-level commas) — descendant combinator only.
     *
     * @return list<int>
     */
    private static function querySelectorAllIdsOneGroup(ObjectEntry $root, string $selectors): array
    {
        $parts = preg_split('/\s+/', $selectors) ?: [];
        if ([] === $parts || (1 === \count($parts) && '' === $parts[0])) {
            throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
        }

        $candidates = self::collectDescendantElements($root);
        if (VmDom::isElement($root)) {
            array_unshift($candidates, $root);
        }

        $filtered = $candidates;
        foreach ($parts as $i => $part) {
            $simple = self::parseSimpleSelector($part);
            if (null === $simple) {
                throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
            }
            if (0 === $i) {
                $filtered = array_values(array_filter(
                    $filtered,
                    static fn (ObjectEntry $el): bool => self::elementMatchesSimple($el, $simple)
                ));
                continue;
            }
            $next = [];
            $seen = [];
            foreach ($filtered as $ancestor) {
                foreach (self::collectDescendantElements($ancestor) as $desc) {
                    if (isset($seen[$desc->id])) {
                        continue;
                    }
                    if (self::elementMatchesSimple($desc, $simple)) {
                        $seen[$desc->id] = true;
                        $next[] = $desc;
                    }
                }
            }
            $filtered = $next;
        }

        $ids = [];
        foreach ($filtered as $el) {
            $ids[] = $el->id;
        }

        return $ids;
    }

    public static function querySelectorAll(Context $ctx, ObjectEntry $root, string $selectors): Variable
    {
        return VmDom::createNodeList($ctx, self::querySelectorAllIds($root, $selectors));
    }

    /**
     * Element.matches() — php-src ext/dom/php_dom.c / ParentNode (#20418).
     */
    public static function matches(ObjectEntry $element, string $selectors): bool
    {
        if (!VmDom::isElement($element)) {
            return false;
        }
        $root = VmDom::ownerDocumentEntry($element) ?? $element;
        $ids = self::querySelectorAllIds($root, $selectors);

        return \in_array($element->id, $ids, true);
    }

    /**
     * Element.closest() — walk ancestors including self (php-src ext/dom/php_dom.c; #20418).
     */
    public static function closest(ObjectEntry $element, string $selectors): ?ObjectEntry
    {
        // Validate selector syntax even when no ancestor matches.
        self::querySelectorAllIds($element, $selectors);

        $current = $element;
        while (null !== $current) {
            if (VmDom::isElement($current) && self::matches($current, $selectors)) {
                return $current;
            }
            if (!DomRegistry::has($current)) {
                return null;
            }
            $parentId = DomRegistry::state($current)->parentId;
            if (null === $parentId) {
                return null;
            }
            $current = DomRegistry::entry($parentId);
        }

        return null;
    }

    /** Dom\HTMLDocument::saveHtml() — php-src html_document.c (#19580). */
    public static function saveHtml(ObjectEntry $document, ?ObjectEntry $node = null): string
    {
        return VmDom::saveHTML($document, $node, 0);
    }

    /**
     * Dom\XMLDocument::createFromString() — php-src ext/dom/xml_document.c (#19581).
     */
    public static function createXmlFromString(
        Context $ctx,
        string $source,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        if ('' === $source) {
            throw new \ValueError('Dom\\XMLDocument::createFromString(): Argument #1 ($source) must not be empty');
        }
        self::assertValidXmlParseOptions($options, 'Dom\\XMLDocument::createFromString()');
        if (null !== $overrideEncoding) {
            self::assertValidDocumentEncoding(
                $overrideEncoding,
                'Dom\\XMLDocument::createFromString()',
                3,
                'overrideEncoding'
            );
        }

        return self::loadXmlDocumentFromSource($ctx, $source, $options, $overrideEncoding, $frame);
    }

    /**
     * Dom\XMLDocument::createFromFile() — php-src ext/dom/xml_document.c load_from_helper(DOM_LOAD_FILE) (#20808).
     */
    public static function createXmlFromFile(
        Context $ctx,
        string $path,
        int $options = 0,
        ?string $overrideEncoding = null,
        ?Frame $frame = null
    ): Variable {
        if ('' === $path) {
            throw new \ValueError('Dom\\XMLDocument::createFromFile(): Argument #1 ($path) must not be empty');
        }
        // php-src xml_document.c — percent-encoded NUL rejected for file paths.
        if (false !== strpos($path, '%00')) {
            throw new \ValueError(
                'Dom\\XMLDocument::createFromFile(): Argument #1 ($path) must not contain percent-encoded NUL bytes'
            );
        }
        self::assertValidXmlParseOptions($options, 'Dom\\XMLDocument::createFromFile()');
        if (null !== $overrideEncoding) {
            self::assertValidDocumentEncoding(
                $overrideEncoding,
                'Dom\\XMLDocument::createFromFile()',
                3,
                'overrideEncoding'
            );
        }

        $contents = \PHPCompiler\ext\standard\VmFsReadNative::read($path);
        if (false === $contents) {
            // php-src: zend_throw_exception_ex(NULL, 0, "Cannot open file '%s'", source);
            throw new \Exception("Cannot open file '".$path."'");
        }

        return self::loadXmlDocumentFromSource($ctx, $contents, $options, $overrideEncoding, $frame);
    }

    /**
     * Shared parse path for Dom\XMLDocument::createFromString / createFromFile (#19581, #20808).
     */
    private static function loadXmlDocumentFromSource(
        Context $ctx,
        string $source,
        int $options,
        ?string $overrideEncoding,
        ?Frame $frame
    ): Variable {
        $document = self::allocateXmlDocument($ctx);
        if (null !== $overrideEncoding) {
            DomRegistry::state($document)->encoding = $overrideEncoding;
        }
        $ok = VmDom::loadXML($ctx, $document, $source, $frame, $options);
        if (!$ok) {
            throw new \DOMException(
                'Invalid State Error',
                DomExceptionConstants::INVALID_STATE_ERR
            );
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    /**
     * Dom\XMLDocument::createEmpty() — php-src ext/dom/xml_document.c (#19581).
     *
     * Unlike HTMLDocument::createEmpty(), this yields a document with no root element.
     */
    public static function createXmlEmpty(
        Context $ctx,
        string $version = '1.0',
        string $encoding = 'UTF-8'
    ): Variable {
        self::assertValidDocumentEncoding(
            $encoding,
            'Dom\\XMLDocument::createEmpty()',
            2,
            'encoding'
        );

        $document = self::allocateXmlDocument($ctx);
        $state = DomRegistry::state($document);
        $state->xmlVersion = $version;
        $state->encoding = $encoding;
        if ($document->hasProperty(VmDom::PROP_XML_VERSION)) {
            $document->getProperty(VmDom::PROP_XML_VERSION)->string($version);
        }
        if ($document->hasProperty(VmDom::PROP_ENCODING)) {
            $document->getProperty(VmDom::PROP_ENCODING)->string($encoding);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    public static function findDirectChildElementByLocalName(ObjectEntry $parent, string $localName): ?ObjectEntry
    {
        $localName = strtolower($localName);
        $state = DomRegistry::state($parent);
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !VmDom::isElement($child)) {
                continue;
            }
            if ($localName === strtolower(DomRegistry::state($child)->nodeName)) {
                return $child;
            }
        }

        return null;
    }

    public static function htmlRootElement(ObjectEntry $document): ?ObjectEntry
    {
        VmDom::ensureDocument($document);
        $rootVar = $document->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $rootVar->type) {
            return null;
        }
        $root = $rootVar->toObject();
        if (!VmDom::isElement($root)) {
            return null;
        }

        return $root;
    }

    public static function htmlBodyElement(ObjectEntry $document): ?ObjectEntry
    {
        $html = self::htmlRootElement($document);
        if (null === $html) {
            return null;
        }

        return self::findDirectChildElementByLocalName($html, 'body');
    }

    public static function htmlHeadElement(ObjectEntry $document): ?ObjectEntry
    {
        $html = self::htmlRootElement($document);
        if (null === $html) {
            return null;
        }

        return self::findDirectChildElementByLocalName($html, 'head');
    }

    public static function findFirstElementByLocalName(ObjectEntry $root, string $localName): ?ObjectEntry
    {
        $localName = strtolower($localName);
        if (VmDom::isElement($root) && $localName === strtolower(DomRegistry::state($root)->nodeName)) {
            return $root;
        }
        foreach (DomRegistry::state($root)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $found = self::findFirstElementByLocalName($child, $localName);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<ObjectEntry>
     */
    private static function collectDescendantElements(ObjectEntry $root): array
    {
        $out = [];
        self::collectDescendantElementsRecursive($root, $out);

        return $out;
    }

    /**
     * @param list<ObjectEntry> $out
     */
    private static function collectDescendantElementsRecursive(ObjectEntry $node, array &$out): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (VmDom::isElement($child)) {
                $out[] = $child;
            }
            self::collectDescendantElementsRecursive($child, $out);
        }
    }

    /**
     * @return array{tag: ?string, id: ?string, classes: list<string>, pseudos: list<string>}|null
     */
    private static function parseSimpleSelector(string $selector): ?array
    {
        $selector = trim($selector);
        if ('' === $selector) {
            return null;
        }
        // Type selector must not absorb `:` (pseudos); colon in tags was a silent misparse (#20866).
        // Optional trailing :first-child / :last-child only — other :foo → SyntaxError via null.
        if (!preg_match(
            '/^(\*|([a-zA-Z][\w-]*))?(#[\w-]+)?((?:\.[\w-]+)*)((?::(?:first-child|last-child))*)$/',
            $selector,
            $m
        )) {
            return null;
        }
        $tagPart = $m[1] ?? '';
        $universal = '*' === $tagPart;
        $tag = ($universal || '' === $tagPart) ? null : strtolower($tagPart);
        $id = isset($m[3]) && '' !== $m[3] ? substr($m[3], 1) : null;
        $classes = [];
        if (isset($m[4]) && '' !== $m[4]) {
            foreach (explode('.', ltrim($m[4], '.')) as $class) {
                if ('' !== $class) {
                    $classes[] = $class;
                }
            }
        }
        $pseudos = [];
        if (isset($m[5]) && '' !== $m[5]) {
            if (preg_match_all('/:(first-child|last-child)/', $m[5], $pm) > 0) {
                foreach ($pm[1] as $pseudo) {
                    $pseudos[] = $pseudo;
                }
            }
        }
        if (!$universal && null === $tag && null === $id && [] === $classes && [] === $pseudos) {
            return null;
        }

        return ['tag' => $tag, 'id' => $id, 'classes' => $classes, 'pseudos' => $pseudos];
    }

    /**
     * @param array{tag: ?string, id: ?string, classes: list<string>, pseudos: list<string>} $simple
     */
    private static function elementMatchesSimple(ObjectEntry $element, array $simple): bool
    {
        $state = DomRegistry::state($element);
        if (null !== $simple['tag'] && $simple['tag'] !== strtolower($state->nodeName)) {
            return false;
        }
        if (null !== $simple['id']) {
            $id = $state->attributes['id'] ?? null;
            if ($simple['id'] !== $id) {
                return false;
            }
        }
        if ([] !== $simple['classes']) {
            $classAttr = $state->attributes['class'] ?? '';
            $present = preg_split('/\s+/', trim($classAttr)) ?: [];
            $presentMap = [];
            foreach ($present as $c) {
                if ('' !== $c) {
                    $presentMap[$c] = true;
                }
            }
            foreach ($simple['classes'] as $need) {
                if (!isset($presentMap[$need])) {
                    return false;
                }
            }
        }
        foreach ($simple['pseudos'] as $pseudo) {
            if ('first-child' === $pseudo) {
                if (!self::elementIsNthChildEdge($element, true)) {
                    return false;
                }
            } elseif ('last-child' === $pseudo) {
                if (!self::elementIsNthChildEdge($element, false)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * CSS :first-child / :last-child — element must be the first/last child node of its parent
     * (any node type; leading/trailing text or comment disqualifies) — Selectors Level 3 / #20866.
     */
    private static function elementIsNthChildEdge(ObjectEntry $element, bool $first): bool
    {
        if (!DomRegistry::has($element)) {
            return false;
        }
        $parentId = DomRegistry::state($element)->parentId;
        if (null === $parentId) {
            return false;
        }
        $parent = DomRegistry::entry($parentId);
        if (null === $parent || !DomRegistry::has($parent)) {
            return false;
        }
        $childIds = DomRegistry::state($parent)->childIds;
        if ([] === $childIds) {
            return false;
        }
        $edgeId = $first ? $childIds[0] : $childIds[\count($childIds) - 1];

        return $edgeId === $element->id;
    }

    private static function applyLivingElementClassMap(DomNodeState $state): void
    {
        if (isset($state->nodeClassMap[VmDom::CLASS_ELEMENT])) {
            return;
        }
        $state->nodeClassMap[VmDom::CLASS_ELEMENT] = self::CLASS_HTML_ELEMENT;
    }

    private static function applyLivingXmlElementClassMap(DomNodeState $state): void
    {
        if (isset($state->nodeClassMap[VmDom::CLASS_ELEMENT])) {
            return;
        }
        $state->nodeClassMap[VmDom::CLASS_ELEMENT] = self::CLASS_ELEMENT;
    }

    private static function assertValidHtmlParseOptions(int $options): void
    {
        $allowed = LibxmlConstants::LIBXML_HTML_NOIMPLIED
            | LibxmlConstants::LIBXML_COMPACT
            | LibxmlConstants::LIBXML_NOERROR
            | DomLivingConstants::HTML_NO_DEFAULT_NS;
        if (0 !== ($options & ~$allowed)) {
            throw new \ValueError('Dom\\HTMLDocument::createFromString(): Argument #2 ($options) contains an invalid option');
        }
    }

    /** php-src ext/dom/xml_document.c check_options_validity(). */
    private static function assertValidXmlParseOptions(int $options, string $method): void
    {
        $allowed = LibxmlConstants::LIBXML_RECOVER
            | LibxmlConstants::LIBXML_NOENT
            | LibxmlConstants::LIBXML_DTDLOAD
            | LibxmlConstants::LIBXML_DTDATTR
            | LibxmlConstants::LIBXML_DTDVALID
            | LibxmlConstants::LIBXML_NOERROR
            | LibxmlConstants::LIBXML_NOWARNING
            | LibxmlConstants::LIBXML_NOBLANKS
            | LibxmlConstants::LIBXML_NSCLEAN
            | LibxmlConstants::LIBXML_NOCDATA
            | LibxmlConstants::LIBXML_NONET
            | LibxmlConstants::LIBXML_PEDANTIC
            | LibxmlConstants::LIBXML_COMPACT
            | LibxmlConstants::LIBXML_PARSEHUGE
            | LibxmlConstants::LIBXML_BIGLINES;
        if (0 !== ($options & ~$allowed)) {
            throw new \ValueError($method.': Argument #2 ($options) contains invalid flags (allowed flags: LIBXML_RECOVER, LIBXML_NOENT, LIBXML_DTDLOAD, LIBXML_DTDATTR, LIBXML_DTDVALID, LIBXML_NOERROR, LIBXML_NOWARNING, LIBXML_NOBLANKS, LIBXML_NSCLEAN, LIBXML_NOCDATA, LIBXML_NONET, LIBXML_PEDANTIC, LIBXML_COMPACT, LIBXML_PARSEHUGE, LIBXML_BIGLINES)');
        }
    }

    /**
     * Approximate libxml xmlFindCharEncodingHandler() for factory args
     * (php-src ext/dom/xml_document.c).
     */
    private static function assertValidDocumentEncoding(
        string $encoding,
        string $method,
        int $argNum,
        string $paramName
    ): void {
        if ('' === $encoding) {
            throw new \ValueError(sprintf(
                '%s: Argument #%d ($%s) must not be empty',
                $method,
                $argNum,
                $paramName
            ));
        }
        $normalized = strtoupper(str_replace(['-', '_'], '', $encoding));
        static $known = [
            'UTF8' => true,
            'UTF16' => true,
            'UTF16LE' => true,
            'UTF16BE' => true,
            'ASCII' => true,
            'USASCII' => true,
            'ISO88591' => true,
            'ISO88592' => true,
            'ISO88593' => true,
            'ISO88594' => true,
            'ISO88595' => true,
            'ISO88596' => true,
            'ISO88597' => true,
            'ISO88598' => true,
            'ISO88599' => true,
            'ISO885910' => true,
            'ISO885913' => true,
            'ISO885914' => true,
            'ISO885915' => true,
            'ISO885916' => true,
            'WINDOWS1250' => true,
            'WINDOWS1251' => true,
            'WINDOWS1252' => true,
            'CP1250' => true,
            'CP1251' => true,
            'CP1252' => true,
            'SHIFTJIS' => true,
            'SJIS' => true,
            'EUCJP' => true,
            'GB2312' => true,
            'GBK' => true,
            'BIG5' => true,
            'KOI8R' => true,
            'KOI8U' => true,
        ];
        if (!isset($known[$normalized])) {
            $msg = 2 === $argNum
                ? sprintf('%s: Argument #%d ($%s) is not a valid document encoding', $method, $argNum, $paramName)
                : sprintf('%s: Argument #%d ($%s) must be a valid document encoding', $method, $argNum, $paramName);
            throw new \ValueError($msg);
        }
    }
}
