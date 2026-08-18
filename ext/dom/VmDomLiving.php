<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
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

    /** php-src Dom\ParentNode (ext/dom/php_dom.stub.php; #20961). */
    public const CLASS_PARENT_NODE = 'dom\\parentnode';

    /** php-src Dom\ChildNode (ext/dom/php_dom.stub.php; #20961). */
    public const CLASS_CHILD_NODE = 'dom\\childnode';

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

    /** SVG namespace URI (https://html.spec.whatwg.org/#svg-namespace; #26033). */
    public const SVG_NS = 'http://www.w3.org/2000/svg';

    /** MathML namespace URI (https://html.spec.whatwg.org/#mathml-namespace; #26033). */
    public const MATHML_NS = 'http://www.w3.org/1998/Math/MathML';

    public const CLASS_DOCUMENT = 'dom\\document';

    public const CLASS_HTML_DOCUMENT = 'dom\\htmldocument';

    public const CLASS_XML_DOCUMENT = 'dom\\xmldocument';

    /** php-src Dom\Implementation (php_dom.stub.php; #20898). */
    public const CLASS_IMPLEMENTATION = 'dom\\implementation';

    /** php-src Dom\DocumentType (php_dom.stub.php; #20910). */
    public const CLASS_DOCUMENT_TYPE = 'dom\\documenttype';

    /** php-src Dom\CharacterData (php_dom.stub.php; #20948). */
    public const CLASS_CHARACTER_DATA = 'dom\\characterdata';

    /** php-src Dom\Text (php_dom.stub.php; #20948). */
    public const CLASS_TEXT = 'dom\\text';

    /** php-src Dom\Comment (php_dom.stub.php; #20948). */
    public const CLASS_COMMENT = 'dom\\comment';

    /** php-src Dom\CDATASection (php_dom.stub.php; #20948). */
    public const CLASS_CDATA = 'dom\\cdatasection';

    /** php-src Dom\ProcessingInstruction (php_dom.stub.php; #20948). */
    public const CLASS_PROCESSING_INSTRUCTION = 'dom\\processinginstruction';

    /** php-src Dom\Attr (php_dom.stub.php; #20948). */
    public const CLASS_ATTR = 'dom\\attr';

    /** php-src Dom\DocumentFragment (php_dom.stub.php; #20948). */
    public const CLASS_DOCUMENT_FRAGMENT = 'dom\\documentfragment';

    /** php-src Dom\NamedNodeMap (php_dom.stub.php; #20948). */
    public const CLASS_NAMED_NODE_MAP = 'dom\\namednodemap';

    /** php-src Dom\DtdNamedNodeMap — DocumentType entities/notations (php_dom.stub.php; #21014). */
    public const CLASS_DTD_NAMED_NODE_MAP = 'dom\\dtdnamednodemap';

    /** php-src Dom\Entity (php_dom.stub.php; #20983). */
    public const CLASS_ENTITY = 'dom\\entity';

    /** php-src Dom\EntityReference (php_dom.stub.php; #20983). */
    public const CLASS_ENTITY_REFERENCE = 'dom\\entityreference';

    /** php-src Dom\Notation (php_dom.stub.php; #20983). */
    public const CLASS_NOTATION = 'dom\\notation';

    /** php-src Dom\DOMException — canonical; legacy DOMException is @alias (php_dom.stub.php; #20983). */
    public const CLASS_DOM_EXCEPTION = 'dom\\domexception';

    /** php-src Dom\NamespaceInfo (php_dom.stub.php; #20924). */
    public const CLASS_NAMESPACE_INFO = 'dom\\namespaceinfo';

    public const PROP_NAMESPACE_INFO_PREFIX = 'prefix';

    public const PROP_NAMESPACE_INFO_NAMESPACE_URI = 'namespaceURI';

    public const PROP_NAMESPACE_INFO_ELEMENT = 'element';

    public const PROP_BODY = 'body';

    public const PROP_HEAD = 'head';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    /** https://html.spec.whatwg.org/#document.title — php-src ext/dom/html_document.c (#19580). */
    public const PROP_TITLE = 'title';

    /** @var array<int, ObjectEntry> */
    private static array $implementationSingletons = [];

    private static ?ClassEntry $implementationClassEntry = null;

    public static function isLivingDocument(ObjectEntry $entry): bool
    {
        $lc = strtolower($entry->class->name);

        return self::CLASS_HTML_DOCUMENT === $lc || self::CLASS_XML_DOCUMENT === $lc;
    }

    /**
     * Dom\Implementation singleton for living Document::$implementation (php-src php_dom.c; #20898).
     */
    public static function implementationSingleton(): ObjectEntry
    {
        if (null === self::$implementationClassEntry) {
            throw new \LogicException('Dom\\Implementation is not registered in this compiler build');
        }
        $key = spl_object_id(self::$implementationClassEntry);
        if (!isset(self::$implementationSingletons[$key])) {
            $entry = new ObjectEntry(self::$implementationClassEntry);
            $entry->constructed = true;
            self::$implementationSingletons[$key] = $entry;
        }

        return self::$implementationSingletons[$key];
    }

    public static function bindImplementationClass(ClassEntry $entry): void
    {
        self::$implementationClassEntry = $entry;
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

    /** Dom\Attr instance (php-src php_dom.stub.php; #21083). */
    public static function isLivingAttr(ObjectEntry $entry): bool
    {
        return self::CLASS_ATTR === strtolower($entry->class->name);
    }

    /**
     * True when $entry is under Dom\Node (php-src dom_modern_node_class_entry; #20940).
     */
    public static function isLivingNodeInstance(ObjectEntry $entry, Context $ctx): bool
    {
        return self::isUnderClass($entry, self::CLASS_NODE, $ctx);
    }

    /**
     * True when $entry is under legacy DOMNode (php-src dom_node_class_entry; #20940).
     */
    public static function isLegacyDomNodeInstance(ObjectEntry $entry, Context $ctx): bool
    {
        return self::isUnderClass($entry, VmDom::CLASS_NODE, $ctx);
    }

    private static function isUnderClass(ObjectEntry $entry, string $wantLc, Context $ctx): bool
    {
        $wantLc = strtolower($wantLc);
        $class = $entry->class;
        for ($guard = 0; null !== $class && $guard < 64; ++$guard) {
            if ($wantLc === strtolower($class->name)) {
                return true;
            }
            if (null === $class->parentLc || !isset($ctx->classes[$class->parentLc])) {
                return false;
            }
            $class = $ctx->classes[$class->parentLc];
        }

        return false;
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
        ?Frame $frame = null,
        string $inputName = 'Entity',
        string $methodLabel = 'Dom\\HTMLDocument::createFromString'
    ): Variable {
        self::assertValidHtmlParseOptions($options);
        if (null !== $overrideEncoding && '' === $overrideEncoding) {
            throw new \ValueError('Dom\\HTMLDocument::createFromString(): Argument #3 ($overrideEncoding) must not be empty');
        }

        self::reportHtmlInitialModeTreeErrors($ctx, $source, $options, $frame, $methodLabel, $inputName);

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

    /**
     * Dom\HTMLDocument::createEmpty() — php-src ext/dom/html_document.c (#26035).
     *
     * Yields a document with no doctype and no documentElement (unlike
     * {@see createHTMLDocument()}, which seeds html/head/body).
     */
    public static function createEmpty(Context $ctx, string $encoding = 'UTF-8'): Variable
    {
        self::assertValidDocumentEncoding(
            $encoding,
            'Dom\\HTMLDocument::createEmpty()',
            1,
            'encoding'
        );

        $document = self::allocateHtmlDocument($ctx);
        $state = DomRegistry::state($document);
        $state->encoding = $encoding;
        if ($document->hasProperty(VmDom::PROP_ENCODING)) {
            $document->getProperty(VmDom::PROP_ENCODING)->string($encoding);
        }

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($document);

        return $var;
    }

    /**
     * Dom\Implementation::createHTMLDocument() — php-src ext/dom/domimplementation.c (#20898, #26035).
     *
     * Seeds doctype + html/head/(optional title)/body. Distinct from {@see createEmpty()}.
     */
    public static function createHTMLDocument(Context $ctx, ?string $title = null): Variable
    {
        // Skeleton matches WHATWG createHTMLDocument / php-src Dom_Implementation::createHTMLDocument.
        $docVar = self::createFromString(
            $ctx,
            '<!DOCTYPE html><html><head></head><body></body></html>',
            0,
            'UTF-8'
        );
        if (null !== $title) {
            self::setHtmlDocumentTitle($ctx, $docVar->toObject(), $title);
        }

        return $docVar;
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

        $docVar = self::createFromString(
            $ctx,
            $contents,
            $options,
            $overrideEncoding,
            $frame,
            $path,
            'Dom\\HTMLDocument::createFromFile'
        );
        // php-src sets document URL from the loaded path (#20898).
        DomRegistry::state($docVar->toObject())->documentUri = $path;

        return $docVar;
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
     * CSS attribute selectors (`[attr]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`,
     * optional `i` flag), descendant / child (`>`) / adjacent-sibling (`+`) /
     * general-sibling (`~`) combinators, and comma selector lists (CSS
     * Selectors Level 3 / php-src Dom\* lexbor; #32061, #32089).
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
     * Match one selector group (no top-level commas).
     *
     * Combinators: descendant (whitespace), child `>`, adjacent sibling `+`,
     * general sibling `~` (CSS Selectors Level 3 / php-src lexbor; #32061).
     *
     * @return list<int>
     */
    private static function querySelectorAllIdsOneGroup(ObjectEntry $root, string $selectors): array
    {
        $compound = self::parseCompoundSelector($selectors);
        if (null === $compound || [] === $compound) {
            throw new \DOMException('SyntaxError', DomExceptionConstants::SYNTAX_ERR);
        }

        $candidates = self::collectDescendantElements($root);
        if (VmDom::isElement($root)) {
            array_unshift($candidates, $root);
        }

        $filtered = array_values(array_filter(
            $candidates,
            static fn (ObjectEntry $el): bool => self::elementMatchesSimple($el, $compound[0]['simple'])
        ));

        $n = \count($compound);
        for ($i = 1; $i < $n; $i++) {
            $comb = $compound[$i]['combinator'];
            $simple = $compound[$i]['simple'];
            $next = [];
            $seen = [];
            foreach ($filtered as $left) {
                foreach (self::elementsReachedByCombinator($left, $comb) as $cand) {
                    if (isset($seen[$cand->id])) {
                        continue;
                    }
                    if (self::elementMatchesSimple($cand, $simple)) {
                        $seen[$cand->id] = true;
                        $next[] = $cand;
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
        $ids = self::querySelectorAllIds($root, $selectors);
        if (VmDomLiving::prefersLivingCollections($root)) {
            return VmDom::createDomNodeList($ctx, $ids);
        }

        return VmDom::createNodeList($ctx, $ids);
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

    /**
     * Dom\HTMLDocument::saveHtml() — php-src html_document.c (#19580, #26924).
     *
     * Living serialize differs from legacy libxml htmlDocDump: no trailing LF, and
     * doctype is glued to the following markup (`<!DOCTYPE html><html>…`) rather than
     * separated by a newline.
     */
    public static function saveHtml(ObjectEntry $document, ?ObjectEntry $node = null): string
    {
        $html = VmDom::saveHTML($document, $node, 0);
        if (null !== $node) {
            return $html;
        }
        // Empty document: libxml-style dump yields a lone "\n"; lexbor emits "" (#26925).
        if ("\n" === $html || '' === $html) {
            return '';
        }
        if (str_ends_with($html, "\n")) {
            $html = substr($html, 0, -1);
        }
        // Strip the legacy newline that formatHtmlDoctype appends after <!DOCTYPE …>.
        if (1 === preg_match('/^(<!DOCTYPE[^>]*>)\n/', $html)) {
            $html = preg_replace('/^(<!DOCTYPE[^>]*>)\n/', '$1', $html, 1) ?? $html;
        }

        return $html;
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

        $docVar = self::loadXmlDocumentFromSource($ctx, $contents, $options, $overrideEncoding, $frame);
        // php-src sets document URL from the loaded path (#20898).
        DomRegistry::state($docVar->toObject())->documentUri = $path;

        return $docVar;
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
     * Like {@see createEmpty()}, yields a document with no root element.
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

    /**
     * Dom\Implementation::createDocumentType() — php-src Dom_Implementation::createDocumentType (#20910).
     */
    public static function createDocumentType(
        Context $ctx,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): Variable {
        return VmDom::createDocumentType(
            $ctx,
            $qualifiedName,
            $publicId,
            $systemId,
            null,
            true
        );
    }

    /**
     * Dom\Implementation::createDocument() — new Dom\XMLDocument (php-src Dom_Implementation::createDocument; #20910).
     */
    public static function createDocument(
        Context $ctx,
        ?string $namespaceUri,
        string $qualifiedName,
        ?ObjectEntry $doctype
    ): Variable {
        return VmDom::createDocument($ctx, $namespaceUri, $qualifiedName, $doctype, true);
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
     * Tokenize one selector group into combinator + simple-selector steps (#32061).
     *
     * First step has combinator `""`. Subsequent steps use ` ` (descendant),
     * `>` (child), `+` (adjacent sibling), or `~` (general sibling). Leading,
     * trailing, or doubled combinators → null (SyntaxError).
     *
     * @return list<array{combinator: string, simple: array{tag: ?string, id: ?string, classes: list<string>, pseudos: list<string>, attrs: list<array{name: string, op: ?string, value: ?string, i: bool}>}}>|null
     */
    private static function parseCompoundSelector(string $selector): ?array
    {
        $selector = trim($selector);
        $len = \strlen($selector);
        if (0 === $len) {
            return null;
        }
        $parts = [];
        $i = 0;
        $expectSimple = true;
        $combinator = '';
        while ($i < $len) {
            $skippedWs = false;
            while ($i < $len && ctype_space($selector[$i])) {
                $skippedWs = true;
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $ch = $selector[$i];
            if ('>' === $ch || '+' === $ch || '~' === $ch) {
                if ($expectSimple) {
                    return null;
                }
                $combinator = $ch;
                $expectSimple = true;
                ++$i;
                continue;
            }
            if ($skippedWs && !$expectSimple && [] !== $parts) {
                $combinator = ' ';
                $expectSimple = true;
            }
            if (!$expectSimple) {
                return null;
            }
            $token = self::takeSimpleSelectorToken($selector, $i, $len);
            if (null === $token) {
                return null;
            }
            $simple = self::parseSimpleSelector($token);
            if (null === $simple) {
                return null;
            }
            $parts[] = [
                'combinator' => [] === $parts ? '' : $combinator,
                'simple' => $simple,
            ];
            $combinator = '';
            $expectSimple = false;
        }
        if ($expectSimple || [] === $parts) {
            return null;
        }

        return $parts;
    }

    /**
     * Consume one simple selector, treating `[…]` and pseudo-function `(…)` bodies
     * as atomic so selector operators inside them are not combinators (#32089, #32108).
     */
    private static function takeSimpleSelectorToken(string $selector, int &$i, int $len): ?string
    {
        $start = $i;
        while ($i < $len) {
            $ch = $selector[$i];
            if ('[' === $ch) {
                $end = self::scanAttributeSelectorClose($selector, $i, $len);
                if (null === $end) {
                    return null;
                }
                $i = $end;
                continue;
            }
            if ('(' === $ch) {
                $end = self::scanParenthesisClose($selector, $i, $len);
                if (null === $end) {
                    return null;
                }
                $i = $end;
                continue;
            }
            if (ctype_space($ch) || '>' === $ch || '+' === $ch || '~' === $ch) {
                break;
            }
            ++$i;
        }
        if ($i === $start) {
            return null;
        }

        return substr($selector, $start, $i - $start);
    }

    /**
     * @return int|null index after the matching `)`, or null if unclosed
     */
    private static function scanParenthesisClose(string $selector, int $i, int $len): ?int
    {
        if ($i >= $len || '(' !== $selector[$i]) {
            return null;
        }
        ++$i;
        $depth = 1;
        $quote = null;
        while ($i < $len) {
            $ch = $selector[$i];
            if (null !== $quote) {
                if ('\\' === $ch && ($i + 1) < $len) {
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                ++$i;
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                ++$i;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                ++$i;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                ++$i;
                if (0 === $depth) {
                    return $i;
                }
                continue;
            }
            ++$i;
        }

        return null;
    }

    /**
     * @return int|null index after the matching `]`, or null if unclosed
     */
    private static function scanAttributeSelectorClose(string $selector, int $i, int $len): ?int
    {
        if ($i >= $len || '[' !== $selector[$i]) {
            return null;
        }
        ++$i;
        $quote = null;
        while ($i < $len) {
            $ch = $selector[$i];
            if (null !== $quote) {
                if ('\\' === $ch && ($i + 1) < $len) {
                    $i += 2;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                ++$i;
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                ++$i;
                continue;
            }
            if (']' === $ch) {
                return $i + 1;
            }
            ++$i;
        }

        return null;
    }

    /**
     * Elements reachable from $left via one CSS combinator (#32061).
     *
     * @return list<ObjectEntry>
     */
    private static function elementsReachedByCombinator(ObjectEntry $left, string $combinator): array
    {
        return match ($combinator) {
            ' ' => self::collectDescendantElements($left),
            '>' => self::collectChildElements($left),
            '+' => self::nextElementSiblingList($left),
            '~' => self::followingElementSiblings($left),
            default => [],
        };
    }

    /**
     * Direct element children only — text/comment siblings are skipped (CSS `>`).
     *
     * @return list<ObjectEntry>
     */
    private static function collectChildElements(ObjectEntry $parent): array
    {
        $out = [];
        if (!DomRegistry::has($parent)) {
            return $out;
        }
        foreach (DomRegistry::state($parent)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isElement($child)) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @return list<ObjectEntry>
     */
    private static function nextElementSiblingList(ObjectEntry $element): array
    {
        $next = self::nextElementSibling($element);

        return null === $next ? [] : [$next];
    }

    private static function nextElementSibling(ObjectEntry $element): ?ObjectEntry
    {
        foreach (self::followingElementSiblings($element) as $sib) {
            return $sib;
        }

        return null;
    }

    /**
     * Following element siblings in tree order (CSS `~` / `+`; non-elements skipped).
     *
     * @return list<ObjectEntry>
     */
    private static function followingElementSiblings(ObjectEntry $element): array
    {
        $out = [];
        if (!DomRegistry::has($element)) {
            return $out;
        }
        $parentId = DomRegistry::state($element)->parentId;
        if (null === $parentId) {
            return $out;
        }
        $parent = DomRegistry::entry($parentId);
        if (null === $parent || !DomRegistry::has($parent)) {
            return $out;
        }
        $found = false;
        foreach (DomRegistry::state($parent)->childIds as $childId) {
            if (!$found) {
                if ($childId === $element->id) {
                    $found = true;
                }
                continue;
            }
            $sib = DomRegistry::entry($childId);
            if (null !== $sib && VmDom::isElement($sib)) {
                $out[] = $sib;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   tag: ?string,
     *   id: ?string,
     *   classes: list<string>,
     *   pseudos: list<array{name: string, arg: ?string}>,
     *   attrs: list<array{name: string, op: ?string, value: ?string, i: bool}>
     * }|null
     */
    private static function parseSimpleSelector(string $selector): ?array
    {
        $selector = trim($selector);
        if ('' === $selector) {
            return null;
        }
        $len = \strlen($selector);
        $i = 0;
        $universal = false;
        $tag = null;
        if ($i < $len && '*' === $selector[$i]) {
            $universal = true;
            ++$i;
        } elseif ($i < $len && 1 === preg_match('/\G[a-zA-Z][\w-]*/A', $selector, $m, 0, $i)) {
            $tag = strtolower($m[0]);
            $i += \strlen($m[0]);
        }
        $id = null;
        $classes = [];
        $pseudos = [];
        $attrs = [];
        while ($i < $len) {
            $ch = $selector[$i];
            if ('#' === $ch) {
                ++$i;
                if (1 !== preg_match('/\G[\w-]+/A', $selector, $m, 0, $i)) {
                    return null;
                }
                $id = $m[0];
                $i += \strlen($m[0]);
                continue;
            }
            if ('.' === $ch) {
                ++$i;
                if (1 !== preg_match('/\G[\w-]+/A', $selector, $m, 0, $i)) {
                    return null;
                }
                $classes[] = $m[0];
                $i += \strlen($m[0]);
                continue;
            }
            if ('[' === $ch) {
                $parsed = self::parseAttributeSelector($selector, $i, $len);
                if (null === $parsed) {
                    return null;
                }
                $attrs[] = $parsed['attr'];
                $i = $parsed['end'];
                continue;
            }
            if (':' === $ch) {
                ++$i;
                if (1 !== preg_match(
                    '/\G(?:first-child|last-child|first-of-type|last-of-type|'
                    .'nth-child|nth-last-child|nth-of-type|nth-last-of-type)/A',
                    $selector,
                    $m,
                    0,
                    $i
                )) {
                    return null;
                }
                $name = strtolower($m[0]);
                $i += \strlen($m[0]);
                $arg = null;
                if (str_starts_with($name, 'nth-')) {
                    if ($i >= $len || '(' !== $selector[$i]) {
                        return null;
                    }
                    $end = self::scanParenthesisClose($selector, $i, $len);
                    if (null === $end) {
                        return null;
                    }
                    $arg = trim(substr($selector, $i + 1, $end - $i - 2));
                    if ('' === $arg) {
                        return null;
                    }
                    if (null === self::parseNthExpression($arg)) {
                        return null;
                    }
                    $i = $end;
                }
                $pseudos[] = ['name' => $name, 'arg' => $arg];
                continue;
            }

            return null;
        }
        if (!$universal && null === $tag && null === $id && [] === $classes && [] === $pseudos && [] === $attrs) {
            return null;
        }

        return ['tag' => $tag, 'id' => $id, 'classes' => $classes, 'pseudos' => $pseudos, 'attrs' => $attrs];
    }

    /**
     * CSS3 attribute selector at `$i` (`[` … `]`). php-src lexbor / Selectors Level 3 (#32089).
     *
     * @return array{attr: array{name: string, op: ?string, value: ?string, i: bool}, end: int}|null
     */
    private static function parseAttributeSelector(string $selector, int $i, int $len): ?array
    {
        if ($i >= $len || '[' !== $selector[$i]) {
            return null;
        }
        ++$i;
        while ($i < $len && ctype_space($selector[$i])) {
            ++$i;
        }
        if (1 !== preg_match('/\G[A-Za-z_][\w:-]*/A', $selector, $m, 0, $i)) {
            return null;
        }
        $name = $m[0];
        $i += \strlen($name);
        while ($i < $len && ctype_space($selector[$i])) {
            ++$i;
        }
        if ($i < $len && ']' === $selector[$i]) {
            return [
                'attr' => ['name' => $name, 'op' => null, 'value' => null, 'i' => false],
                'end' => $i + 1,
            ];
        }
        $op = null;
        if (($i + 1) < $len && '=' === $selector[$i + 1]
            && ('~' === $selector[$i] || '|' === $selector[$i] || '^' === $selector[$i]
                || '$' === $selector[$i] || '*' === $selector[$i])
        ) {
            $op = $selector[$i].'=';
            $i += 2;
        } elseif ($i < $len && '=' === $selector[$i]) {
            $op = '=';
            ++$i;
        } else {
            return null;
        }
        while ($i < $len && ctype_space($selector[$i])) {
            ++$i;
        }
        $value = self::parseAttributeSelectorValue($selector, $i, $len);
        if (null === $value) {
            return null;
        }
        $i = $value['end'];
        while ($i < $len && ctype_space($selector[$i])) {
            ++$i;
        }
        $ci = false;
        if ($i < $len && ('i' === $selector[$i] || 'I' === $selector[$i])
            && (($i + 1) >= $len || !ctype_alnum($selector[$i + 1]))
        ) {
            $ci = true;
            ++$i;
            while ($i < $len && ctype_space($selector[$i])) {
                ++$i;
            }
        } elseif ($i < $len && ('s' === $selector[$i] || 'S' === $selector[$i])
            && (($i + 1) >= $len || !ctype_alnum($selector[$i + 1]))
        ) {
            ++$i;
            while ($i < $len && ctype_space($selector[$i])) {
                ++$i;
            }
        }
        if ($i >= $len || ']' !== $selector[$i]) {
            return null;
        }

        return [
            'attr' => ['name' => $name, 'op' => $op, 'value' => $value['value'], 'i' => $ci],
            'end' => $i + 1,
        ];
    }

    /**
     * @return array{value: string, end: int}|null
     */
    private static function parseAttributeSelectorValue(string $selector, int $i, int $len): ?array
    {
        if ($i >= $len) {
            return null;
        }
        $ch = $selector[$i];
        if ('"' === $ch || "'" === $ch) {
            $quote = $ch;
            ++$i;
            $value = '';
            while ($i < $len && $selector[$i] !== $quote) {
                if ('\\' === $selector[$i] && ($i + 1) < $len) {
                    $value .= $selector[$i + 1];
                    $i += 2;
                    continue;
                }
                $value .= $selector[$i];
                ++$i;
            }
            if ($i >= $len || $selector[$i] !== $quote) {
                return null;
            }

            return ['value' => $value, 'end' => $i + 1];
        }
        $start = $i;
        while ($i < $len && ']' !== $selector[$i] && '"' !== $selector[$i] && "'" !== $selector[$i]
            && !ctype_space($selector[$i])
        ) {
            ++$i;
        }
        if ($i === $start) {
            return null;
        }

        return ['value' => substr($selector, $start, $i - $start), 'end' => $i];
    }

    /**
     * @param array{
     *   tag: ?string,
     *   id: ?string,
     *   classes: list<string>,
     *   pseudos: list<array{name: string, arg: ?string}>,
     *   attrs: list<array{name: string, op: ?string, value: ?string, i: bool}>
     * } $simple
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
        foreach ($simple['attrs'] as $attr) {
            if (!self::elementMatchesAttributeSelector($element, $attr)) {
                return false;
            }
        }
        foreach ($simple['pseudos'] as $pseudo) {
            if (!self::matchesStructuralPseudo($element, $pseudo['name'], $pseudo['arg'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * CSS3 attribute selector match (php-src lexbor / Selectors Level 3; #32089).
     *
     * HTML documents ASCII-lowercase the selector attribute name before compare
     * (php-src #17802 / WHATWG case-sensitivity of selectors). Empty `~=` `^=`
     * `$=` `*=` values match nothing.
     *
     * @param array{name: string, op: ?string, value: ?string, i: bool} $attr
     */
    private static function elementMatchesAttributeSelector(ObjectEntry $element, array $attr): bool
    {
        $state = DomRegistry::state($element);
        $name = $attr['name'];
        $owner = VmDom::ownerDocumentEntry($element);
        $html = null !== $owner && self::CLASS_HTML_DOCUMENT === strtolower($owner->class->name);
        if ($html) {
            $name = strtolower($name);
        }
        if (!\array_key_exists($name, $state->attributes)) {
            return false;
        }
        if (null === $attr['op']) {
            return true;
        }
        $actual = (string) $state->attributes[$name];
        $want = (string) $attr['value'];
        if ($attr['i']) {
            $actual = strtolower($actual);
            $want = strtolower($want);
        }

        return match ($attr['op']) {
            '=' => $actual === $want,
            '~=' => self::attributeIncludesWord($actual, $want),
            '|=' => $actual === $want || str_starts_with($actual, $want.'-'),
            '^=' => '' !== $want && str_starts_with($actual, $want),
            '$=' => '' !== $want && str_ends_with($actual, $want),
            '*=' => '' !== $want && str_contains($actual, $want),
            default => false,
        };
    }

    private static function attributeIncludesWord(string $actual, string $want): bool
    {
        if ('' === $want || str_contains($want, ' ') || str_contains($want, "\t")
            || str_contains($want, "\n") || str_contains($want, "\r")
        ) {
            return false;
        }
        $words = preg_split('/\s+/', trim($actual)) ?: [];
        foreach ($words as $word) {
            if ($word === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{elementIndex: int, elementCount: int, typeIndex: int, typeCount: int}|null
     */
    private static function elementSiblingPositions(ObjectEntry $element): ?array
    {
        if (!DomRegistry::has($element)) {
            return null;
        }
        $parentId = DomRegistry::state($element)->parentId;
        if (null === $parentId) {
            return null;
        }
        $parent = DomRegistry::entry($parentId);
        if (null === $parent || !DomRegistry::has($parent)) {
            return null;
        }
        $state = DomRegistry::state($element);
        $name = strtolower($state->nodeName);
        $elementIndex = 0;
        $elementCount = 0;
        $typeIndex = 0;
        $typeCount = 0;
        foreach (DomRegistry::state($parent)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !VmDom::isElement($child)) {
                continue;
            }
            ++$elementCount;
            if ($childId === $element->id) {
                $elementIndex = $elementCount;
            }
            if (strtolower(DomRegistry::state($child)->nodeName) === $name) {
                ++$typeCount;
                if ($childId === $element->id) {
                    $typeIndex = $typeCount;
                }
            }
        }
        if (0 === $elementIndex || 0 === $elementCount || 0 === $typeIndex || 0 === $typeCount) {
            return null;
        }

        return [
            'elementIndex' => $elementIndex,
            'elementCount' => $elementCount,
            'typeIndex' => $typeIndex,
            'typeCount' => $typeCount,
        ];
    }

    private static function matchesStructuralPseudo(ObjectEntry $element, string $name, ?string $arg): bool
    {
        $pos = self::elementSiblingPositions($element);
        if (null === $pos) {
            return false;
        }

        return match ($name) {
            'first-child' => 1 === $pos['elementIndex'],
            'last-child' => $pos['elementCount'] === $pos['elementIndex'],
            'first-of-type' => 1 === $pos['typeIndex'],
            'last-of-type' => $pos['typeCount'] === $pos['typeIndex'],
            'nth-child' => null !== $arg && self::nthExpressionMatches($arg, $pos['elementIndex']),
            'nth-last-child' => null !== $arg
                && self::nthExpressionMatches($arg, $pos['elementCount'] - $pos['elementIndex'] + 1),
            'nth-of-type' => null !== $arg && self::nthExpressionMatches($arg, $pos['typeIndex']),
            'nth-last-of-type' => null !== $arg
                && self::nthExpressionMatches($arg, $pos['typeCount'] - $pos['typeIndex'] + 1),
            default => false,
        };
    }

    private static function nthExpressionMatches(string $expression, int $index): bool
    {
        $parsed = self::parseNthExpression($expression);
        if (null === $parsed) {
            return false;
        }
        $a = $parsed['a'];
        $b = $parsed['b'];
        if (0 === $a) {
            return $index === $b;
        }
        if ($a > 0) {
            if ($index < $b) {
                return false;
            }

            return 0 === (($index - $b) % $a);
        }
        if ($index > $b) {
            return false;
        }

        return 0 === (($b - $index) % (-$a));
    }

    /**
     * @return array{a: int, b: int}|null
     */
    private static function parseNthExpression(string $expression): ?array
    {
        $expr = strtolower(preg_replace('/\s+/', '', $expression) ?? '');
        if ('' === $expr) {
            return null;
        }
        if ('odd' === $expr) {
            return ['a' => 2, 'b' => 1];
        }
        if ('even' === $expr) {
            return ['a' => 2, 'b' => 0];
        }
        if (1 === preg_match('/^[+-]?\d+$/', $expr)) {
            return ['a' => 0, 'b' => (int) $expr];
        }
        if (1 !== preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $expr, $m)) {
            return null;
        }
        $aRaw = $m[1] ?? '';
        $a = match ($aRaw) {
            '', '+' => 1,
            '-' => -1,
            default => (int) $aRaw,
        };
        $b = isset($m[2]) && '' !== $m[2] ? (int) $m[2] : 0;

        return ['a' => $a, 'b' => $b];
    }

    private static function applyLivingElementClassMap(DomNodeState $state): void
    {
        self::applyLivingLeafClassMap($state, self::CLASS_HTML_ELEMENT);
    }

    /** @internal used by DomXmlDocumentCreateFromStringJitHelper (#27108). */
    public static function applyLivingXmlElementClassMap(DomNodeState $state): void
    {
        self::applyLivingLeafClassMap($state, self::CLASS_ELEMENT);
    }

    /**
     * Living documents remap legacy DOM* bases → Dom\* (php-src nodeClassMap / #20948).
     *
     * @param string $elementLc Dom\HTMLElement or Dom\Element class lc
     */
    private static function applyLivingLeafClassMap(DomNodeState $state, string $elementLc): void
    {
        $map = [
            VmDom::CLASS_ELEMENT => $elementLc,
            VmDom::CLASS_CHARACTER_DATA => self::CLASS_CHARACTER_DATA,
            VmDom::CLASS_TEXT => self::CLASS_TEXT,
            VmDom::CLASS_COMMENT => self::CLASS_COMMENT,
            VmDom::CLASS_CDATA => self::CLASS_CDATA,
            VmDom::CLASS_PROCESSING_INSTRUCTION => self::CLASS_PROCESSING_INSTRUCTION,
            VmDom::CLASS_ATTR => self::CLASS_ATTR,
            VmDom::CLASS_DOCUMENT_FRAGMENT => self::CLASS_DOCUMENT_FRAGMENT,
            VmDom::CLASS_DOCUMENT_TYPE => self::CLASS_DOCUMENT_TYPE,
            VmDom::CLASS_NODE_LIST => self::CLASS_NODE_LIST,
            VmDom::CLASS_NAMED_NODE_MAP => self::CLASS_NAMED_NODE_MAP,
            // DTD leaf types (#20983).
            VmDom::CLASS_ENTITY => self::CLASS_ENTITY,
            VmDom::CLASS_ENTITY_REFERENCE => self::CLASS_ENTITY_REFERENCE,
            VmDom::CLASS_NOTATION => self::CLASS_NOTATION,
        ];
        foreach ($map as $baseLc => $livingLc) {
            if (!isset($state->nodeClassMap[$baseLc])) {
                $state->nodeClassMap[$baseLc] = $livingLc;
            }
        }
    }

    /** True when $node belongs to a living Dom\* document tree (#20948). */
    public static function prefersLivingCollections(ObjectEntry $node): bool
    {
        if (self::isLivingDocument($node) || self::isLivingElement($node)) {
            return true;
        }
        $lc = strtolower($node->class->name);
        if (self::CLASS_DOCUMENT === $lc
            || self::CLASS_DOCUMENT_FRAGMENT === $lc
            || self::CLASS_DOCUMENT_TYPE === $lc
        ) {
            return true;
        }
        // Leaf Dom\* nodes (Text/Attr/…) use Dom\NodeList / Dom\NamedNodeMap.
        if (str_starts_with($lc, 'dom\\')
            && (
                self::CLASS_TEXT === $lc
                || self::CLASS_COMMENT === $lc
                || self::CLASS_CDATA === $lc
                || self::CLASS_ATTR === $lc
                || self::CLASS_CHARACTER_DATA === $lc
                || self::CLASS_PROCESSING_INSTRUCTION === $lc
                || self::CLASS_ENTITY === $lc
                || self::CLASS_ENTITY_REFERENCE === $lc
                || self::CLASS_NOTATION === $lc
                || self::CLASS_NODE === $lc
            )
        ) {
            return true;
        }
        $owner = VmDom::ownerDocumentEntry($node);

        return null !== $owner && self::isLivingDocument($owner);
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

    /**
     * Lexbor UNTOININMO tree-error surface for Dom\HTMLDocument factories
     * (php-src ext/dom/html_document.c dom_lexbor_libxml2_bridge_tree_error_reporter; #21523).
     *
     * HTML5 initial insertion mode rejects start/end tags (and non-whitespace characters
     * when later markup forces reprocessing) before a DOCTYPE. LIBXML_NOERROR silences;
     * LIBXML_HTML_NOIMPLIED suppresses line-1 UNTOININMO like php-src.
     */
    private static function reportHtmlInitialModeTreeErrors(
        Context $ctx,
        string $source,
        int $options,
        ?Frame $frame,
        string $methodLabel,
        string $inputName
    ): void {
        if (0 !== ($options & LibxmlConstants::LIBXML_NOERROR)) {
            return;
        }

        $token = self::findInitialModeUnexpectedToken($source);
        if (null === $token) {
            return;
        }
        // php-src: line==1 && html_no_implied && UNTOININMO → skip (mimic libxml no-doctype silence).
        if (1 === $token['line'] && 0 !== ($options & LibxmlConstants::LIBXML_HTML_NOIMPLIED)) {
            return;
        }

        $columnPart = $token['len'] <= 1
            ? (string) $token['column']
            : $token['column'].'-'.($token['column'] + $token['len'] - 1);
        $body = sprintf(
            'tree error unexpected-token-in-initial-mode in %s, line: %d, column: %s',
            $inputName,
            $token['line'],
            $columnPart
        );
        $record = [
            'level' => LibxmlConstants::LIBXML_ERR_ERROR,
            'code' => 1,
            'column' => $token['column'],
            'message' => $body,
            'file' => $inputName,
            'line' => $token['line'],
        ];
        VmLibxml::handleError($ctx, $record, $frame, null, $methodLabel.'(): '.$body);
    }

    /**
     * Locate the first HTML5 initial-mode unexpected token (start/end tag name, or leading
     * character when later markup is present). Returns 1-based line/column and token length.
     *
     * @return array{line: int, column: int, len: int}|null
     */
    private static function findInitialModeUnexpectedToken(string $source): ?array
    {
        $len = \strlen($source);
        $i = 0;
        if ($len >= 3 && "\xEF\xBB\xBF" === substr($source, 0, 3)) {
            $i = 3;
        }
        $line = 1;
        $column = 1;

        while ($i < $len) {
            $ch = $source[$i];
            if (' ' === $ch || "\t" === $ch || "\r" === $ch || "\f" === $ch) {
                ++$i;
                ++$column;
                continue;
            }
            if ("\n" === $ch) {
                ++$i;
                ++$line;
                $column = 1;
                continue;
            }

            // Comments are allowed in initial mode (php-src / HTML5).
            if ($i + 3 < $len && '<!--' === substr($source, $i, 4)) {
                $end = strpos($source, '-->', $i + 4);
                if (false === $end) {
                    return null;
                }
                for ($j = $i; $j < $end + 3; ++$j) {
                    if ("\n" === $source[$j]) {
                        ++$line;
                        $column = 1;
                    } else {
                        ++$column;
                    }
                }
                $i = $end + 3;
                continue;
            }

            // DOCTYPE is the only start that stays in initial mode without error.
            if ($i + 1 < $len && '<' === $ch
                && 1 === preg_match('/^<!DOCTYPE\b/i', substr($source, $i))
            ) {
                return null;
            }

            // End tag: </name — name starts at column after "</".
            if ($i + 1 < $len && '<' === $ch && '/' === $source[$i + 1]) {
                $nameStart = $i + 2;
                $nameCol = $column + 2;
                $nameLen = 0;
                while ($nameStart + $nameLen < $len
                    && 1 === preg_match('/[A-Za-z0-9:-]/', $source[$nameStart + $nameLen])
                ) {
                    ++$nameLen;
                }
                if ($nameLen > 0) {
                    return ['line' => $line, 'column' => $nameCol, 'len' => $nameLen];
                }

                return ['line' => $line, 'column' => $column, 'len' => 1];
            }

            // Start tag: <Name
            if ('<' === $ch && $i + 1 < $len && 1 === preg_match('/[A-Za-z]/', $source[$i + 1])) {
                $nameStart = $i + 1;
                $nameCol = $column + 1;
                $nameLen = 0;
                while ($nameStart + $nameLen < $len
                    && 1 === preg_match('/[A-Za-z0-9:-]/', $source[$nameStart + $nameLen])
                ) {
                    ++$nameLen;
                }

                return ['line' => $line, 'column' => $nameCol, 'len' => max(1, $nameLen)];
            }

            // Character token: Zend reports UNTOININMO only when later markup forces reprocess
            // (plain text-only documents stay silent).
            if (self::htmlSourceHasMarkupAfter($source, $i)) {
                return ['line' => $line, 'column' => $column, 'len' => 1];
            }

            return null;
        }

        return null;
    }

    /** True when a start/end tag or DOCTYPE appears at or after $from. */
    private static function htmlSourceHasMarkupAfter(string $source, int $from): bool
    {
        $len = \strlen($source);
        for ($i = $from; $i < $len; ++$i) {
            if ('<' !== $source[$i]) {
                continue;
            }
            if ($i + 1 >= $len) {
                return false;
            }
            $next = $source[$i + 1];
            if ('/' === $next || 1 === preg_match('/[A-Za-z]/', $next)) {
                return true;
            }
            if (1 === preg_match('/^<!DOCTYPE\b/i', substr($source, $i))) {
                return true;
            }
        }

        return false;
    }

    /** php-src ext/dom/xml_document.c check_options_validity(). */
    /** @internal used by DomXmlDocumentCreateFromStringJitHelper (#27108). */
    public static function assertValidXmlParseOptions(int $options, string $method): void
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

    /**
     * Dom\Element::getInScopeNamespaces() — XPath in-scope xmlns attrs (php-src element.c; #20924).
     *
     * @return list<ObjectEntry> Dom\NamespaceInfo objects; NamespaceInfo::$element is always $element
     */
    public static function getInScopeNamespaces(Context $ctx, ObjectEntry $element): array
    {
        $prefixToUri = [];
        $current = $element;
        while (DomRegistry::has($current) && VmDom::isElement($current)) {
            $state = DomRegistry::state($current);
            // Reverse attribute order so later decls win for the same prefix (php-src element.c).
            $prefixes = array_keys($state->xmlnsAttributePrefixes);
            for ($i = \count($prefixes) - 1; $i >= 0; --$i) {
                $prefix = $prefixes[$i];
                if (\array_key_exists($prefix, $prefixToUri)) {
                    continue;
                }
                if (!\array_key_exists($prefix, $state->namespaceDeclarations)) {
                    continue;
                }
                $prefixToUri[$prefix] = $state->namespaceDeclarations[$prefix];
            }
            $parentId = $state->parentId;
            if (null === $parentId) {
                break;
            }
            $parent = DomRegistry::entry($parentId);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        $infos = [];
        // Reverse insertion order (ZEND_HASH_MAP_REVERSE_FOREACH).
        foreach (array_reverse($prefixToUri, true) as $prefix => $uri) {
            if ('' === $prefix && '' === $uri) {
                // Empty default xmlns undeclares — omitted (XPath namespace-nodes).
                continue;
            }
            $infos[] = self::createNamespaceInfo(
                $ctx,
                '' === $prefix ? null : $prefix,
                '' === $uri ? null : $uri,
                $element
            );
        }

        return $infos;
    }

    /**
     * Dom\Element::getDescendantNamespaces() (php-src element.c; #20924).
     *
     * @return list<ObjectEntry>
     */
    public static function getDescendantNamespaces(Context $ctx, ObjectEntry $element): array
    {
        $infos = self::getInScopeNamespaces($ctx, $element);
        foreach (self::collectDescendantElements($element) as $desc) {
            foreach (self::getInScopeNamespaces($ctx, $desc) as $info) {
                $infos[] = $info;
            }
        }

        return $infos;
    }

    /**
     * Dom\Element::rename() — QName + namespaceURI update (php-src element.c; #20924).
     */
    public static function renameElement(ObjectEntry $element, ?string $namespaceUri, string $qualifiedName): void
    {
        VmDom::renameElement($element, $namespaceUri, $qualifiedName);
    }

    /**
     * Dom\Attr::rename() — QName + namespaceURI update (php-src element.c alias; #21083).
     */
    public static function renameAttr(
        Context $ctx,
        ObjectEntry $attr,
        ?string $namespaceUri,
        string $qualifiedName
    ): void {
        VmDom::renameAttr($ctx, $attr, $namespaceUri, $qualifiedName);
    }

    /**
     * Build Dom\NamespaceInfo with prefix / namespaceURI / element (php-src php_dom.stub.php; #20924).
     */
    public static function createNamespaceInfo(
        Context $ctx,
        ?string $prefix,
        ?string $namespaceUri,
        ObjectEntry $element
    ): ObjectEntry {
        $class = $ctx->classes[self::CLASS_NAMESPACE_INFO] ?? null;
        if (null === $class) {
            throw new \LogicException('Dom\\NamespaceInfo is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        if (null === $prefix) {
            $entry->getProperty(self::PROP_NAMESPACE_INFO_PREFIX)->null();
        } else {
            $entry->getProperty(self::PROP_NAMESPACE_INFO_PREFIX)->string($prefix);
        }
        if (null === $namespaceUri) {
            $entry->getProperty(self::PROP_NAMESPACE_INFO_NAMESPACE_URI)->null();
        } else {
            $entry->getProperty(self::PROP_NAMESPACE_INFO_NAMESPACE_URI)->string($namespaceUri);
        }
        $entry->getProperty(self::PROP_NAMESPACE_INFO_ELEMENT)->object($element);

        return $entry;
    }

    /**
     * Pack NamespaceInfo objects into a VM array (php-src list<NamespaceInfo>; #20924).
     *
     * @param list<ObjectEntry> $infos
     */
    public static function namespaceInfoListToArray(array $infos): HashTable
    {
        $ht = new HashTable();
        foreach ($infos as $info) {
            $v = new Variable(Variable::TYPE_OBJECT);
            $v->object($info);
            $ht->append($v);
        }

        return $ht;
    }
}
