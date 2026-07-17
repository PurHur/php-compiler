<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/** DOMXPath evaluation engine (php-src ext/dom/xpath.c; #6066). */
final class VmDomXPath
{
    /** DOMXPath::quote() — escape XPath string literals (php-src ext/dom/xpath.c; #18650). */
    public static function quote(string $input): string
    {
        if (!str_contains($input, "'")) {
            return "'".$input."'";
        }
        if (!str_contains($input, '"')) {
            return '"'.$input.'"';
        }

        $parts = [];
        $remaining = $input;
        while ('' !== $remaining) {
            $bytesUntilSingle = strcspn($remaining, "'");
            $bytesUntilDouble = strcspn($remaining, '"');
            $quote = $bytesUntilSingle > $bytesUntilDouble ? "'" : '"';
            $bytesUntilQuote = max($bytesUntilSingle, $bytesUntilDouble);
            $parts[] = $quote.substr($remaining, 0, $bytesUntilQuote).$quote;
            $remaining = substr($remaining, $bytesUntilQuote);
        }

        return 'concat('.implode(',', $parts).')';
    }

    public static function create(Context $ctx, ObjectEntry $document): ObjectEntry
    {
        VmDom::ensureDocument($document);
        $class = $ctx->classes[VmDom::CLASS_XPATH] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMXPath is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_XPATH;
        $state->nodeName = 'DOMXPath';
        $state->xpathDocumentId = $document->id;
        DomRegistry::attach($entry, $state);

        return $entry;
    }

    public static function registerNamespace(ObjectEntry $xpath, string $prefix, string $namespaceUri): bool
    {
        self::ensureXPath($xpath);
        DomRegistry::state($xpath)->xpathNamespaces[$prefix] = $namespaceUri;

        return true;
    }

    /**
     * DOMXPath::registerPhpFunctions() — php-src xpath_callbacks.c REG_FUNC_MODE (#19331).
     *
     * @param Variable|null $restrict null / TYPE_NULL = allow all; string or list of strings = SET mode
     */
    public static function registerPhpFunctions(ObjectEntry $xpath, ?Variable $restrict = null): void
    {
        self::ensureXPath($xpath);
        $state = DomRegistry::state($xpath);
        if (null === $restrict || Variable::TYPE_NULL === $restrict->type) {
            $state->xpathPhpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_ALL;
            $state->xpathPhpFunctions = [];

            return;
        }
        if (Variable::TYPE_STRING === $restrict->type) {
            $name = $restrict->toString();
            self::assertValidPhpFunctionName($name, false);
            $state->xpathPhpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_SET;
            $state->xpathPhpFunctions[$name] = true;

            return;
        }
        if (Variable::TYPE_ARRAY === $restrict->type || Variable::TYPE_HASHTABLE === $restrict->type) {
            $state->xpathPhpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_SET;
            foreach ($restrict->toArray()->iterateKeyed(false) as $pair) {
                [, $value] = $pair;
                $value = $value->resolveIndirect();
                if (Variable::TYPE_STRING !== $value->type) {
                    throw new \TypeError(
                        'DOMXPath::registerPhpFunctions(): Argument #1 ($restrict) must be of type array|string|null, array given with non-string values'
                    );
                }
                $name = $value->toString();
                self::assertValidPhpFunctionName($name, true);
                $state->xpathPhpFunctions[$name] = true;
            }

            return;
        }
        throw new \TypeError(sprintf(
            'DOMXPath::registerPhpFunctions(): Argument #1 ($restrict) must be of type array|string|null, %s given',
            VmDom::typeLabel($restrict)
        ));
    }

    /**
     * DOMXPath::registerPhpFunctionNS() — php-src xpath.c / xpath_callbacks.c (#20119).
     */
    public static function registerPhpFunctionNS(
        Context $ctx,
        ObjectEntry $xpath,
        string $namespaceUri,
        string $name,
        Variable $callable
    ): void {
        self::ensureXPath($xpath);
        if (str_contains($namespaceUri, "\0")) {
            throw new \ValueError(
                'DOMXPath::registerPhpFunctionNS(): Argument #1 ($namespaceURI) must not contain any null bytes'
            );
        }
        if (DomConstants::PHP_XPATH_NS === $namespaceUri) {
            throw new \ValueError(
                'DOMXPath::registerPhpFunctionNS(): Argument #1 ($namespaceURI) must not be "http://php.net/xpath" because it is reserved by PHP'
            );
        }
        if (str_contains($name, "\0")) {
            throw new \ValueError(
                'DOMXPath::registerPhpFunctionNS(): Argument #2 ($name) must not contain any null bytes'
            );
        }
        if (!self::isValidXPathNcName($name)) {
            throw new \ValueError(
                'DOMXPath::registerPhpFunctionNS(): Argument #2 ($name) must be a valid callback name'
            );
        }
        $callable = $callable->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callable)) {
            throw new \TypeError(sprintf(
                'DOMXPath::registerPhpFunctionNS(): Argument #3 ($callable) must be of type callable, %s given',
                VmDom::typeLabel($callable)
            ));
        }
        $state = DomRegistry::state($xpath);
        if (!isset($state->xpathPhpFunctionNs[$namespaceUri])) {
            $state->xpathPhpFunctionNs[$namespaceUri] = [];
        }
        $state->xpathPhpFunctionNs[$namespaceUri][$name] = $callable;
    }

    /** xmlValidateNCName(name, 0) subset used by php-src xpath_callbacks.c (#20119). */
    private static function isValidXPathNcName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        // NCName: no colon; Letter|'_' start; then NameChar without ':'.
        return 1 === preg_match('/^[A-Za-z_][\w.-]*$/u', $name);
    }

    public static function query(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode = null,
        bool $registerNodeNS = false
    ): Variable {
        $nodeIds = self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, $registerNodeNS);

        return VmDom::createNodeList($ctx, $nodeIds);
    }

    public static function evaluate(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode = null,
        bool $registerNodeNS = false
    ): Variable {
        $expression = trim($expression);
        $phpFn = self::tryEvaluatePhpFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if (null !== $phpFn) {
            return $phpFn;
        }
        $nsFn = self::tryEvaluateNamespacedPhpFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if (null !== $nsFn) {
            return $nsFn;
        }
        if (self::isBooleanExpression($expression)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(self::evaluateBoolean($ctx, $xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isNumericExpression($expression)) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float(self::evaluateNumber($ctx, $xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isStringExpression($expression)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string(self::evaluateString($ctx, $xpath, $expression, $contextNode));

            return $var;
        }

        return self::query($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @return list<int>
     */
    private static function evaluateNodeSet(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS
    ): array {
        self::ensureXPath($xpath);
        $expression = trim($expression);
        if ('' === $expression) {
            throw new \DOMException('Invalid expression');
        }

        $state = DomRegistry::state($xpath);
        $document = DomRegistry::entry($state->xpathDocumentId ?? 0);
        if (null === $document || !VmDom::isDocument($document)) {
            throw new \DOMException('Couldn\'t allocate a DOMXPath object');
        }

        $context = $contextNode ?? $document;
        if (!VmDom::isDomNode($context)) {
            throw new \TypeError('DOMXPath::query(): Argument #2 ($context) must be of type ?DOMNode, DOMNode given');
        }
        if ($registerNodeNS) {
            self::registerContextNodeNamespaces($xpath, $context);
        }

        // Union: a|b — document order, unique (#20257; C14N nodeset + attrs).
        if (str_contains($expression, '|')) {
            return self::evaluateUnionNodeSet($ctx, $xpath, $expression, $context, $registerNodeNS);
        }

        // Relative location paths: `.` / `.//…` / `./…` (XPath 1.0; #20257).
        if ('.' === $expression) {
            return DomRegistry::has($context) ? [$context->id] : [];
        }
        if (str_starts_with($expression, './/')) {
            return self::evaluateRelativeDescendantPath(
                $ctx,
                $xpath,
                substr($expression, 3),
                $context,
                $state->xpathNamespaces
            );
        }
        if (str_starts_with($expression, './')) {
            return self::evaluateNodeSet($ctx, $xpath, substr($expression, 2), $context, false);
        }

        // Relative attribute axis: @attr on context element (needed for NS php preds; #20119).
        if (preg_match('~^@([\w.-]+)$~', $expression, $attrMatch)) {
            if (!VmDom::isElement($context)) {
                return [];
            }
            $attr = self::attributeNodeFromElement($ctx, $context, $attrMatch[1], $state->xpathNamespaces);

            return null !== $attr ? [$attr->id] : [];
        }

        // Namespace axis: namespace::* / //namespace::* / path/namespace::* (php-src/libxml; #20097, #20170).
        $nsIds = self::tryEvaluateNamespaceAxis($ctx, $context, $expression, $state->xpathNamespaces);
        if (null !== $nsIds) {
            return $nsIds;
        }

        // Attribute axis: //@id, //a/@id, //a[1]/@id (php-src/libxml string-value of Attr; #19352).
        $attrIds = self::tryEvaluateAttributeAxis($ctx, $context, $expression, $state->xpathNamespaces);
        if (null !== $attrIds) {
            return $attrIds;
        }

        // //tag[prefix:fn(args) = "lit"] / //tag[prefix:fn(args)] — registerPhpFunctionNS (#20119).
        $nsPredIds = self::tryEvaluateNamespacedPhpFunctionPredicate(
            $ctx,
            $xpath,
            $context,
            $expression,
            $state
        );
        if (null !== $nsPredIds) {
            return $nsPredIds;
        }

        // //tag, //tag[@attr='v'], //tag[n] — positional preds keep element string-value (#19456).
        if (preg_match(
            '~^//([*\w][\w:-]*)(?:\[(?:@([^\]=]+)=["\']([^"\']*)["\']|(\d+))\])?$~',
            $expression,
            $matches
        )) {
            $tag = $matches[1];
            $attr = isset($matches[2]) && '' !== $matches[2] ? $matches[2] : null;
            $attrValue = $matches[3] ?? '';
            $position = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : null;
            $nodeIds = self::collectDescendantElements($context, $tag, $state->xpathNamespaces);
            if (null !== $attr) {
                $nodeIds = array_values(array_filter(
                    $nodeIds,
                    static fn (int $id): bool => self::elementAttributeEquals(
                        DomRegistry::entry($id),
                        $attr,
                        $attrValue,
                        $state->xpathNamespaces
                    )
                ));
            }
            if (null !== $position) {
                if ($position < 1 || $position > \count($nodeIds)) {
                    return [];
                }

                return [$nodeIds[$position - 1]];
            }

            return $nodeIds;
        }

        if (preg_match('~^/(.+)$~', $expression, $matches)) {
            return self::evaluateAbsolutePath($context, $matches[1], $state->xpathNamespaces);
        }

        if (preg_match('~^([*\w][\w:-]*)$~', $expression, $matches)) {
            return self::collectChildElements($context, $matches[1], $state->xpathNamespaces);
        }

        // //text() — all text nodes under context (document when context is doc; #20257).
        if ('//text()' === $expression) {
            return self::collectDescendantTextNodes($context, false);
        }

        throw new \DOMException('Invalid expression');
    }

    /**
     * Evaluate `a|b|…` unions; results unique in first-seen order (#20257).
     *
     * @return list<int>
     */
    private static function evaluateUnionNodeSet(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ObjectEntry $context,
        bool $registerNodeNS
    ): array {
        $parts = preg_split('/\|/', $expression) ?: [];
        $seen = [];
        $ids = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            foreach (self::evaluateNodeSet($ctx, $xpath, $part, $context, $registerNodeNS) as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * `.//inner` — descendant axis from context (excludes context self for element tests; #20257).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function evaluateRelativeDescendantPath(
        Context $ctx,
        ObjectEntry $xpath,
        string $inner,
        ObjectEntry $context,
        array $namespaces
    ): array {
        $inner = trim($inner);
        if ('' === $inner) {
            throw new \DOMException('Invalid expression');
        }
        if ('text()' === $inner) {
            return self::collectDescendantTextNodes($context, true);
        }
        // .//@attr / .//tag/@attr — attribute axis under context descendants.
        $attrIds = self::tryEvaluateAttributeAxis($ctx, $context, '//'.$inner, $namespaces);
        if (null !== $attrIds) {
            // //@attr via collectDescendantElements includes context when it matches; exclude
            // attributes whose owner is the context only when inner is bare @attr? Keep as-is —
            // .//@id should include attrs on context element (descendant-or-self for attrs).
            return $attrIds;
        }
        // .//tag[@a='v'] / .//tag[n] / .//tag / .//*
        if (preg_match(
            '~^([*\w][\w:-]*)(?:\[(?:@([^\]=]+)=["\']([^"\']*)["\']|(\d+))\])?$~',
            $inner,
            $matches
        )) {
            $tag = $matches[1];
            $attr = isset($matches[2]) && '' !== $matches[2] ? $matches[2] : null;
            $attrValue = $matches[3] ?? '';
            $position = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : null;
            $nodeIds = self::collectDescendantElements($context, $tag, $namespaces);
            // `.//y` ≡ descendant::y — exclude context even when it matches the tag.
            $nodeIds = array_values(array_filter(
                $nodeIds,
                static fn (int $id): bool => $id !== $context->id
            ));
            if (null !== $attr) {
                $nodeIds = array_values(array_filter(
                    $nodeIds,
                    static fn (int $id): bool => self::elementAttributeEquals(
                        DomRegistry::entry($id),
                        $attr,
                        $attrValue,
                        $namespaces
                    )
                ));
            }
            if (null !== $position) {
                if ($position < 1 || $position > \count($nodeIds)) {
                    return [];
                }

                return [$nodeIds[$position - 1]];
            }

            return $nodeIds;
        }
        unset($xpath);

        throw new \DOMException('Invalid expression');
    }

    /**
     * Collect text nodes under $context (optionally excluding walking into non-elements first).
     *
     * @return list<int>
     */
    private static function collectDescendantTextNodes(ObjectEntry $context, bool $descendantsOnly): array
    {
        $ids = [];
        self::collectDescendantTextNodesRecursive($context, $ids, $descendantsOnly ? false : true);

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    private static function collectDescendantTextNodesRecursive(
        ObjectEntry $node,
        array &$ids,
        bool $includeSelf
    ): void {
        if ($includeSelf && DomRegistry::has($node) && VmDom::isTextNode($node)) {
            $ids[] = $node->id;
        }
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (VmDom::isTextNode($child)) {
                $ids[] = $child->id;
            }
            if (VmDom::isElement($child)) {
                self::collectDescendantTextNodesRecursive($child, $ids, false);
            }
        }
    }

    /**
     * Resolve namespace-axis paths to DOMNameSpaceNode ids (in-scope; #20097, #20170).
     *
     * Supports relative `namespace::*` / `namespace::prefix`, descendant `//namespace::*`,
     * location paths `//tag/namespace::*`, absolute `/a/b/namespace::prefix`, and
     * relative `tag/namespace::*`. Absolute/`//` forms are document-scoped like libxml
     * (DOMXPath ignores the context node for leading `/`).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>|null null when expression is not a namespace-axis path
     */
    private static function tryEvaluateNamespaceAxis(
        Context $ctx,
        ObjectEntry $context,
        string $expression,
        array $namespaces
    ): ?array {
        // Relative: namespace::* / namespace::prefix on the context element (#20097).
        if (preg_match('~^namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            if (!VmDom::isElement($context)) {
                return [];
            }

            return self::namespaceAxisNodesForElement($ctx, $context, $matches[1]);
        }

        // //namespace::* / //namespace::prefix — every element in document order (#20170).
        if (preg_match('~^//namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            return self::namespaceAxisNodesForElementIds(
                $ctx,
                self::collectDocumentElementIds($context),
                $matches[1]
            );
        }

        // //tag/namespace::* / //tag/namespace::prefix (#20170).
        if (preg_match('~^//([*\w][\w:-]*)/namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            $document = self::ownerDocumentOrSelf($context);
            if (null === $document) {
                return [];
            }

            return self::namespaceAxisNodesForElementIds(
                $ctx,
                self::collectDescendantElements($document, $matches[1], $namespaces),
                $matches[2]
            );
        }

        // /abs/path/namespace::* — evaluate element path then namespace axis (#20170).
        if (preg_match('~^/(.+)/namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            return self::namespaceAxisNodesForElementIds(
                $ctx,
                self::evaluateAbsolutePath($context, $matches[1], $namespaces),
                $matches[2]
            );
        }

        // Relative tag/namespace::* from context (#20170).
        if (preg_match('~^([*\w][\w:-]*)/namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            return self::namespaceAxisNodesForElementIds(
                $ctx,
                self::collectChildElements($context, $matches[1], $namespaces),
                $matches[2]
            );
        }

        return null;
    }

    /**
     * In-scope DOMNameSpaceNode ids for one element (`*` = all prefixes).
     *
     * @return list<int>
     */
    private static function namespaceAxisNodesForElement(
        Context $ctx,
        ObjectEntry $element,
        string $want
    ): array {
        $wantPrefix = '*' === $want ? null : $want;
        $ids = [];
        foreach (self::collectInScopeNamespaces($element) as $prefix => $uri) {
            if (null !== $wantPrefix && $prefix !== $wantPrefix) {
                continue;
            }
            $ids[] = VmDom::createNameSpaceNode($ctx, $element, $prefix, $uri)->id;
        }

        return $ids;
    }

    /**
     * @param list<int> $elementIds
     *
     * @return list<int>
     */
    private static function namespaceAxisNodesForElementIds(
        Context $ctx,
        array $elementIds,
        string $want
    ): array {
        $ids = [];
        foreach ($elementIds as $elementId) {
            $element = DomRegistry::entry($elementId);
            if (null === $element || !VmDom::isElement($element)) {
                continue;
            }
            foreach (self::namespaceAxisNodesForElement($ctx, $element, $want) as $nsId) {
                $ids[] = $nsId;
            }
        }

        return $ids;
    }

    /**
     * All element ids in the owner document (document order).
     *
     * @return list<int>
     */
    private static function collectDocumentElementIds(ObjectEntry $context): array
    {
        $document = self::ownerDocumentOrSelf($context);
        if (null === $document) {
            return [];
        }

        return VmDom::collectElementsByTagName($document, '*');
    }

    private static function ownerDocumentOrSelf(ObjectEntry $context): ?ObjectEntry
    {
        if (VmDom::isDocument($context)) {
            return $context;
        }
        $document = VmDom::ownerDocumentEntry($context);

        return null !== $document && VmDom::isDocument($document) ? $document : null;
    }

    /**
     * In-scope prefix→URI map at $context (xml always present; nearer decls win).
     *
     * Order matches libxml nsDef (reverse document order per element, root→context;
     * redeclared prefixes move to the end). php-src/libxml; #20097.
     *
     * @return array<string, string>
     */
    private static function collectInScopeNamespaces(ObjectEntry $context): array
    {
        $chain = [];
        $current = $context;
        while (DomRegistry::has($current)) {
            if (VmDom::isElement($current)) {
                $chain[] = $current;
            }
            $state = DomRegistry::state($current);
            if (null === $state->parentId) {
                break;
            }
            $parent = DomRegistry::entry($state->parentId);
            if (null === $parent || VmDom::isDocument($parent)) {
                break;
            }
            $current = $parent;
        }

        $inScope = ['xml' => DomConstants::XML_NS_URI];
        for ($i = \count($chain) - 1; $i >= 0; --$i) {
            $decls = DomRegistry::state($chain[$i])->namespaceDeclarations;
            // libxml nsDef is a prepend list → reverse document order.
            $prefixes = array_reverse(array_keys($decls));
            foreach ($prefixes as $prefix) {
                unset($inScope[$prefix]);
                $inScope[$prefix] = $decls[$prefix];
            }
        }

        return $inScope;
    }

    /**
     * Resolve //@attr / //tag/@attr / //tag[n]/@attr to Attr node ids (document order).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>|null null when expression is not an attribute-axis path
     */
    private static function tryEvaluateAttributeAxis(
        Context $ctx,
        ObjectEntry $context,
        string $expression,
        array $namespaces
    ): ?array {
        // //@attr — every attribute with that name under context
        if (preg_match('~^//@([\w.-]+)$~', $expression, $matches)) {
            return self::collectDescendantAttributeNodes($ctx, $context, $matches[1], $namespaces, null, null);
        }
        // //tag/@attr or //tag[n]/@attr
        if (preg_match('~^//([*\w][\w:-]*)(?:\[(\d+)\])?/@([\w.-]+)$~', $expression, $matches)) {
            $position = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : null;

            return self::collectDescendantAttributeNodes(
                $ctx,
                $context,
                $matches[3],
                $namespaces,
                $matches[1],
                $position
            );
        }

        return null;
    }

    /**
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectDescendantAttributeNodes(
        Context $ctx,
        ObjectEntry $context,
        string $attrName,
        array $namespaces,
        ?string $elementTag,
        ?int $position
    ): array {
        $elements = null === $elementTag
            ? self::collectDescendantElements($context, '*', $namespaces)
            : self::collectDescendantElements($context, $elementTag, $namespaces);
        if (null !== $position) {
            if ($position < 1 || $position > \count($elements)) {
                return [];
            }
            $elements = [$elements[$position - 1]];
        }
        $attrIds = [];
        foreach ($elements as $elementId) {
            $element = DomRegistry::entry($elementId);
            if (null === $element || !VmDom::isElement($element)) {
                continue;
            }
            $attrVar = self::attributeNodeFromElement($ctx, $element, $attrName, $namespaces);
            if (null === $attrVar) {
                continue;
            }
            $attrIds[] = $attrVar->id;
        }

        return $attrIds;
    }

    /**
     * @param array<string, string> $namespaces
     */
    private static function attributeNodeFromElement(
        Context $ctx,
        ObjectEntry $element,
        string $attrName,
        array $namespaces
    ): ?ObjectEntry {
        if (str_contains($attrName, ':')) {
            [$prefix, $local] = explode(':', $attrName, 2);
            $namespace = $namespaces[$prefix] ?? null;
            if (null === $namespace) {
                return null;
            }
            $attrVar = VmDom::getAttributeNodeNS($ctx, $element, $namespace, $local);
        } else {
            $attrVar = VmDom::getAttributeNode($ctx, $element, $attrName);
        }
        $attrVar = $attrVar->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            return null;
        }
        $attr = $attrVar->toObject();

        return VmDom::isAttr($attr) ? $attr : null;
    }

    private static function ensureXPath(ObjectEntry $xpath): void
    {
        if (!DomRegistry::has($xpath) || DomConstants::XML_XPATH !== DomRegistry::state($xpath)->nodeType) {
            throw new \LogicException('DOMXPath method called on non-xpath object');
        }
    }

    private static function registerContextNodeNamespaces(ObjectEntry $xpath, ObjectEntry $context): void
    {
        if (!VmDom::isElement($context)) {
            return;
        }
        $state = DomRegistry::state($context);
        foreach ($state->namespaceDeclarations as $prefix => $uri) {
            self::registerNamespace($xpath, $prefix, $uri);
        }
    }

    /**
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectDescendantElements(
        ObjectEntry $context,
        string $tag,
        array $namespaces
    ): array {
        [$localName, $namespaceUri] = self::resolveQName($tag, $namespaces);
        if (null !== $namespaceUri) {
            return VmDom::collectElementsByTagNameNS($context, $namespaceUri, $localName);
        }

        return VmDom::collectElementsByTagName($context, $localName);
    }

    /**
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectChildElements(
        ObjectEntry $context,
        string $tag,
        array $namespaces
    ): array {
        [$localName, $namespaceUri] = self::resolveQName($tag, $namespaces);
        $matches = [];
        if (!DomRegistry::has($context)) {
            return $matches;
        }
        foreach (DomRegistry::state($context)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child || !VmDom::isElement($child)) {
                continue;
            }
            if (self::elementMatchesTag($child, $localName, $namespaceUri)) {
                $matches[] = $child->id;
            }
        }

        return $matches;
    }

    /**
     * Absolute location path from the document root (XPath 1.0 / php-src xpath.c; #19709).
     *
     * Leading `/` selects from the document node — not from documentElement as context —
     * so `/r` matches the root element `r`, and `/r/a` walks child axis from there.
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function evaluateAbsolutePath(
        ObjectEntry $context,
        string $path,
        array $namespaces
    ): array {
        $document = VmDom::isDocument($context)
            ? $context
            : VmDom::ownerDocumentEntry($context);
        if (null === $document || !VmDom::isDocument($document)) {
            return [];
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' !== $segment) {
                $segments[] = $segment;
            }
        }
        if ([] === $segments) {
            return [];
        }

        $currentIds = self::collectChildElements($document, $segments[0], $namespaces);
        $n = \count($segments);
        for ($i = 1; $i < $n; ++$i) {
            $nextIds = [];
            foreach ($currentIds as $id) {
                $node = DomRegistry::entry($id);
                if (null === $node) {
                    continue;
                }
                foreach (self::collectChildElements($node, $segments[$i], $namespaces) as $childId) {
                    $nextIds[] = $childId;
                }
            }
            $currentIds = $nextIds;
            if ([] === $currentIds) {
                return [];
            }
        }

        return $currentIds;
    }

    /**
     * @param array<string, string> $namespaces
     *
     * @return array{0: string, 1: ?string}
     */
    private static function resolveQName(string $qName, array $namespaces): array
    {
        if ('*' === $qName) {
            return ['*', null];
        }
        if (!str_contains($qName, ':')) {
            return [$qName, null];
        }
        [$prefix, $local] = explode(':', $qName, 2);
        if (!isset($namespaces[$prefix])) {
            throw new \DOMException('Undefined namespace prefix');
        }

        return [$local, $namespaces[$prefix]];
    }

    private static function elementMatchesTag(
        ObjectEntry $element,
        string $localName,
        ?string $namespaceUri
    ): bool {
        if (!VmDom::isElement($element)) {
            return false;
        }
        $name = VmDom::readLocalName($element);
        $nameMatch = '*' === $localName || $name === $localName;
        if (!$nameMatch) {
            return false;
        }
        if (null === $namespaceUri) {
            return true;
        }
        $ns = VmDom::readNamespaceUri($element) ?? '';

        return '*' === $namespaceUri || $ns === $namespaceUri;
    }

    /**
     * @param array<string, string> $namespaces
     */
    private static function elementAttributeEquals(
        ?ObjectEntry $element,
        string $attrName,
        string $attrValue,
        array $namespaces
    ): bool {
        if (null === $element || !VmDom::isElement($element)) {
            return false;
        }
        if (str_contains($attrName, ':')) {
            [$prefix, $local] = explode(':', $attrName, 2);
            $namespace = $namespaces[$prefix] ?? null;
            if (null === $namespace) {
                return false;
            }

            return VmDom::getAttributeNS($element, $namespace, $local) === $attrValue;
        }

        return VmDom::getAttributeNS($element, null, $attrName) === $attrValue;
    }

    private static function isBooleanExpression(string $expression): bool
    {
        if (preg_match('~^(true|false|boolean\(|not\()~i', $expression)) {
            return true;
        }

        // XPath 1.0 comparisons (= != < <= > >=) at top level (#20280).
        return null !== self::findTopLevelComparison($expression);
    }

    private static function isNumericExpression(string $expression): bool
    {
        if (preg_match('~^(number|count|sum)\(~i', $expression)) {
            return true;
        }
        if (self::isNumericLiteral($expression)) {
            return true;
        }
        // Comparisons are boolean, not numeric (#20280).
        if (null !== self::findTopLevelComparison($expression)) {
            return false;
        }

        return null !== self::findTopLevelAdditive($expression)
            || null !== self::findTopLevelMultiplicative($expression);
    }

    private static function isStringExpression(string $expression): bool
    {
        return (bool) preg_match('~^(string|name)\(~i', $expression);
    }

    private static function isNumericLiteral(string $expression): bool
    {
        return 1 === preg_match('~^[+-]?(?:\d+\.?\d*|\.\d+)$~', $expression);
    }

    private static function evaluateBoolean(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): bool {
        if (0 === strcasecmp($expression, 'true()')) {
            return true;
        }
        if (0 === strcasecmp($expression, 'false()')) {
            return false;
        }
        if (preg_match('~^not\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'not');
            if (null === $inner) {
                throw new \DOMException('Invalid expression');
            }

            return !self::booleanize(self::evaluateToMixed($ctx, $xpath, $inner, $contextNode));
        }
        $comparison = self::findTopLevelComparison($expression);
        if (null !== $comparison) {
            return self::evaluateComparison(
                $ctx,
                $xpath,
                $comparison['left'],
                $comparison['op'],
                $comparison['right'],
                $contextNode
            );
        }
        if (preg_match('~^boolean\((.+)\)$~i', $expression, $matches)) {
            $inner = trim($matches[1]);
            if (preg_match('~^count\((.+)\)$~i', $inner)) {
                return 0.0 !== self::evaluateNumber($ctx, $xpath, $inner, $contextNode);
            }
            if (preg_match('~^string\((.+)\)$~i', $inner)) {
                return '' !== self::evaluateString($ctx, $xpath, $inner, $contextNode);
            }
            if (preg_match('~^(number|sum)\((.+)\)$~i', $inner)) {
                $number = self::evaluateNumber($ctx, $xpath, $inner, $contextNode);

                return 0.0 !== $number && !is_nan($number);
            }
            try {
                $nodeIds = self::evaluateNodeSet($ctx, $xpath, $inner, $contextNode, false);

                return [] !== $nodeIds;
            } catch (\DOMException) {
                // Fall through to string-value coercion for unsupported inner shapes.
            }
            $value = self::evaluateScalar($ctx, $xpath, $inner, $contextNode);

            return self::booleanize($value);
        }

        throw new \DOMException('Invalid expression');
    }

    private static function evaluateNumber(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): float {
        $expression = trim($expression);
        if (self::isNumericLiteral($expression)) {
            return (float) $expression;
        }
        if (preg_match('~^count\((.+)\)$~i', $expression, $matches)) {
            return (float) \count(self::evaluateNodeSet($ctx, $xpath, trim($matches[1]), $contextNode, false));
        }
        if (preg_match('~^number\((.+)\)$~i', $expression, $matches)) {
            $value = self::evaluateToMixed($ctx, $xpath, trim($matches[1]), $contextNode);

            return self::numberize($value);
        }
        // XPath 1.0 sum(node-set): coerce each string-value to number (#19682).
        if (preg_match('~^sum\((.+)\)$~i', $expression, $matches)) {
            $nodeIds = self::evaluateNodeSet($ctx, $xpath, trim($matches[1]), $contextNode, false);
            $sum = 0.0;
            foreach ($nodeIds as $nodeId) {
                $node = DomRegistry::entry($nodeId);
                if (null === $node) {
                    continue;
                }
                $value = '';
                if (VmDom::isElement($node) || VmDom::isTextNode($node) || VmDom::isAttr($node)) {
                    $value = VmDom::readNodeValue($node) ?? '';
                }
                if (!is_numeric($value)) {
                    return NAN;
                }
                $sum += (float) $value;
            }

            return $sum;
        }
        // Additive / multiplicative at top level (#20280).
        $additive = self::findTopLevelAdditive($expression);
        if (null !== $additive) {
            $left = self::evaluateNumber($ctx, $xpath, $additive['left'], $contextNode);
            $right = self::evaluateNumber($ctx, $xpath, $additive['right'], $contextNode);
            if ('+' === $additive['op']) {
                return $left + $right;
            }

            return $left - $right;
        }
        $multiplicative = self::findTopLevelMultiplicative($expression);
        if (null !== $multiplicative) {
            $left = self::evaluateNumber($ctx, $xpath, $multiplicative['left'], $contextNode);
            $right = self::evaluateNumber($ctx, $xpath, $multiplicative['right'], $contextNode);
            if ('*' === $multiplicative['op']) {
                return $left * $right;
            }
            if ('div' === $multiplicative['op']) {
                return 0.0 === $right ? NAN : $left / $right;
            }

            // mod — XPath 1.0 uses floating remainder toward zero like fmod.
            return 0.0 === $right ? NAN : fmod($left, $right);
        }

        throw new \DOMException('Invalid expression');
    }

    private static function evaluateString(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): string {
        if (preg_match('~^name\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'name');
            if (null === $inner) {
                throw new \DOMException('Invalid expression');
            }
            if ('' === $inner) {
                // name() with no args — context node name (XPath 1.0).
                $node = $contextNode;
                if (null === $node) {
                    $state = DomRegistry::state($xpath);
                    $node = DomRegistry::entry($state->xpathDocumentId ?? 0);
                }
                if (null === $node || !DomRegistry::has($node)) {
                    return '';
                }

                return DomRegistry::state($node)->nodeName ?? '';
            }
            $nodeIds = self::evaluateNodeSet($ctx, $xpath, $inner, $contextNode, false);
            if ([] === $nodeIds) {
                return '';
            }
            $node = DomRegistry::entry($nodeIds[0]);
            if (null === $node || !DomRegistry::has($node)) {
                return '';
            }

            return DomRegistry::state($node)->nodeName ?? '';
        }
        if (!preg_match('~^string\((.+)\)$~i', $expression, $matches)) {
            throw new \DOMException('Invalid expression');
        }
        $value = self::evaluateToMixed($ctx, $xpath, trim($matches[1]), $contextNode);
        if (is_array($value)) {
            if ([] === $value) {
                return '';
            }
            $node = DomRegistry::entry($value[0]);
            if (null === $node) {
                return '';
            }

            return VmDom::readNodeValue($node) ?? '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '';
    }

    /**
     * Evaluate an XPath sub-expression to bool|float|string|list<nodeId> (#20280).
     *
     * @return bool|float|string|list<int>
     */
    private static function evaluateToMixed(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): mixed {
        $expression = trim($expression);
        if ('' === $expression) {
            throw new \DOMException('Invalid expression');
        }
        if (self::isBooleanExpression($expression)) {
            return self::evaluateBoolean($ctx, $xpath, $expression, $contextNode);
        }
        if (self::isNumericExpression($expression)) {
            return self::evaluateNumber($ctx, $xpath, $expression, $contextNode);
        }
        if (self::isStringExpression($expression)) {
            return self::evaluateString($ctx, $xpath, $expression, $contextNode);
        }

        return self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, false);
    }

    /**
     * XPath 1.0 comparison for the evaluate() surface (#20280).
     * Number/number and node-set/number paths cover the common count()/literal cases.
     */
    private static function evaluateComparison(
        Context $ctx,
        ObjectEntry $xpath,
        string $leftExpr,
        string $op,
        string $rightExpr,
        ?ObjectEntry $contextNode
    ): bool {
        $left = self::evaluateToMixed($ctx, $xpath, $leftExpr, $contextNode);
        $right = self::evaluateToMixed($ctx, $xpath, $rightExpr, $contextNode);

        if (is_array($left) || is_array($right)) {
            return self::compareWithNodeSet($left, $op, $right);
        }

        // Prefer numeric comparison when either side is numeric (XPath converts both).
        if (is_float($left) || is_int($left) || is_float($right) || is_int($right)
            || (is_string($left) && is_numeric($left)) || (is_string($right) && is_numeric($right))) {
            return self::compareNumbers(self::numberize($left), $op, self::numberize($right));
        }
        if (is_bool($left) || is_bool($right)) {
            return self::compareNumbers(
                self::booleanize($left) ? 1.0 : 0.0,
                $op,
                self::booleanize($right) ? 1.0 : 0.0
            );
        }

        $leftStr = is_string($left) ? $left : '';
        $rightStr = is_string($right) ? $right : '';
        if ('=' === $op) {
            return $leftStr === $rightStr;
        }
        if ('!=' === $op) {
            return $leftStr !== $rightStr;
        }

        return self::compareNumbers(self::numberize($leftStr), $op, self::numberize($rightStr));
    }

    /**
     * @param bool|float|string|list<int> $left
     * @param bool|float|string|list<int> $right
     */
    private static function compareWithNodeSet(mixed $left, string $op, mixed $right): bool
    {
        $leftNodes = is_array($left) ? $left : null;
        $rightNodes = is_array($right) ? $right : null;
        if (null !== $leftNodes && null !== $rightNodes) {
            foreach ($leftNodes as $leftId) {
                $leftNode = DomRegistry::entry($leftId);
                $leftStr = null !== $leftNode ? (VmDom::readNodeValue($leftNode) ?? '') : '';
                foreach ($rightNodes as $rightId) {
                    $rightNode = DomRegistry::entry($rightId);
                    $rightStr = null !== $rightNode ? (VmDom::readNodeValue($rightNode) ?? '') : '';
                    if (self::compareScalarsAsXPath($leftStr, $op, $rightStr)) {
                        return true;
                    }
                }
            }

            return false;
        }
        if (null !== $leftNodes) {
            foreach ($leftNodes as $leftId) {
                $leftNode = DomRegistry::entry($leftId);
                $leftStr = null !== $leftNode ? (VmDom::readNodeValue($leftNode) ?? '') : '';
                if (self::compareScalarsAsXPath($leftStr, $op, $right)) {
                    return true;
                }
            }

            return false;
        }
        foreach ($rightNodes ?? [] as $rightId) {
            $rightNode = DomRegistry::entry($rightId);
            $rightStr = null !== $rightNode ? (VmDom::readNodeValue($rightNode) ?? '') : '';
            if (self::compareScalarsAsXPath($left, $op, $rightStr)) {
                return true;
            }
        }

        return false;
    }

    private static function compareScalarsAsXPath(mixed $left, string $op, mixed $right): bool
    {
        if (is_float($left) || is_int($left) || is_float($right) || is_int($right)
            || (is_string($left) && is_numeric($left) && !is_bool($right))
            || (is_string($right) && is_numeric($right) && !is_bool($left))) {
            if ('=' === $op || '!=' === $op) {
                // Node-set vs number: convert node string-value to number (XPath 1.0).
                $ok = self::compareNumbers(self::numberize($left), '=', self::numberize($right));

                return '!=' === $op ? !$ok : $ok;
            }

            return self::compareNumbers(self::numberize($left), $op, self::numberize($right));
        }
        $leftStr = is_string($left) ? $left : (is_bool($left) ? ($left ? 'true' : 'false') : (string) self::numberize($left));
        $rightStr = is_string($right) ? $right : (is_bool($right) ? ($right ? 'true' : 'false') : (string) self::numberize($right));
        if ('=' === $op) {
            return $leftStr === $rightStr;
        }
        if ('!=' === $op) {
            return $leftStr !== $rightStr;
        }

        return self::compareNumbers(self::numberize($leftStr), $op, self::numberize($rightStr));
    }

    private static function compareNumbers(float $left, string $op, float $right): bool
    {
        return match ($op) {
            '=' => $left === $right,
            '!=' => $left !== $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => false,
        };
    }

    private static function numberize(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_array($value)) {
            if ([] === $value) {
                return NAN;
            }
            $node = DomRegistry::entry($value[0]);
            $str = null !== $node ? (VmDom::readNodeValue($node) ?? '') : '';

            return is_numeric($str) ? (float) $str : NAN;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ('' === $trimmed || !is_numeric($trimmed)) {
                return NAN;
            }

            return (float) $trimmed;
        }

        return NAN;
    }

    /**
     * @return array{op: string, left: string, right: string}|null
     */
    private static function findTopLevelComparison(string $expression): ?array
    {
        $ops = ['<=', '>=', '!=', '=', '<', '>'];
        $depth = 0;
        $quote = null;
        $len = \strlen($expression);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $expression[$i];
            if (null !== $quote) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                continue;
            }
            if (0 !== $depth) {
                continue;
            }
            foreach ($ops as $op) {
                $opLen = \strlen($op);
                if ($i + $opLen <= $len && substr($expression, $i, $opLen) === $op) {
                    // Avoid treating the '=' in '<=' / '>=' / '!=' when shorter ops are listed last.
                    $left = trim(substr($expression, 0, $i));
                    $right = trim(substr($expression, $i + $opLen));
                    if ('' === $left || '' === $right) {
                        return null;
                    }

                    return ['op' => $op, 'left' => $left, 'right' => $right];
                }
            }
        }

        return null;
    }

    /**
     * Leftmost top-level binary + or - (#20280).
     *
     * @return array{op: string, left: string, right: string}|null
     */
    private static function findTopLevelAdditive(string $expression): ?array
    {
        $depth = 0;
        $quote = null;
        $len = \strlen($expression);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $expression[$i];
            if (null !== $quote) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                continue;
            }
            if (0 !== $depth || ('+' !== $ch && '-' !== $ch)) {
                continue;
            }
            if (!self::isBinaryAdditiveAt($expression, $i)) {
                continue;
            }
            $left = trim(substr($expression, 0, $i));
            $right = trim(substr($expression, $i + 1));
            if ('' === $left || '' === $right) {
                continue;
            }

            return ['op' => $ch, 'left' => $left, 'right' => $right];
        }

        return null;
    }

    private static function isBinaryAdditiveAt(string $expression, int $index): bool
    {
        $op = $expression[$index];
        $hasSpaceBefore = $index > 0 && 1 === preg_match('/\s/', $expression[$index - 1]);
        for ($j = $index - 1; $j >= 0; --$j) {
            $prev = $expression[$j];
            if (1 === preg_match('/\s/', $prev)) {
                continue;
            }
            // Unary +/− after open paren, comma, or another operator.
            if ('(' === $prev || ',' === $prev) {
                return false;
            }
            if (in_array($prev, ['+', '-', '*', '=', '<', '>', '!'], true)) {
                return false;
            }
            // Subtraction vs NCName hyphen: `foo-bar` stays a name; `1-2`, `count(//a)-1`,
            // and `foo - bar` are binary (#20280).
            if ('-' === $op) {
                if (')' === $prev || ']' === $prev || ctype_digit($prev) || '.' === $prev) {
                    return true;
                }

                return $hasSpaceBefore;
            }

            return true;
        }

        return false;
    }

    /**
     * Leftmost top-level * / div / mod (#20280).
     *
     * @return array{op: string, left: string, right: string}|null
     */
    private static function findTopLevelMultiplicative(string $expression): ?array
    {
        $depth = 0;
        $quote = null;
        $len = \strlen($expression);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $expression[$i];
            if (null !== $quote) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                continue;
            }
            if (0 !== $depth) {
                continue;
            }
            if ('*' === $ch) {
                $left = trim(substr($expression, 0, $i));
                $right = trim(substr($expression, $i + 1));
                if ('' !== $left && '' !== $right) {
                    return ['op' => '*', 'left' => $left, 'right' => $right];
                }
            }
            foreach (['div', 'mod'] as $word) {
                $wordLen = \strlen($word);
                if ($i + $wordLen > $len || 0 !== substr_compare($expression, $word, $i, $wordLen, true)) {
                    continue;
                }
                $beforeOk = 0 === $i || !preg_match('/[\w.-]/', $expression[$i - 1]);
                $afterOk = $i + $wordLen >= $len || !preg_match('/[\w.-]/', $expression[$i + $wordLen]);
                if (!$beforeOk || !$afterOk) {
                    continue;
                }
                $left = trim(substr($expression, 0, $i));
                $right = trim(substr($expression, $i + $wordLen));
                if ('' !== $left && '' !== $right) {
                    return ['op' => strtolower($word), 'left' => $left, 'right' => $right];
                }
            }
        }

        return null;
    }

    /** Inner text of func(...) when the call spans the whole expression. */
    private static function wrappedFunctionInner(string $expression, string $funcName): ?string
    {
        if (!preg_match('~^'.preg_quote($funcName, '~').'\(~i', $expression)) {
            return null;
        }
        $openParen = strpos($expression, '(');
        if (false === $openParen) {
            return null;
        }
        $closeParen = self::findMatchingCloseParen($expression, $openParen);
        if (null === $closeParen || $closeParen !== \strlen($expression) - 1) {
            return null;
        }

        return trim(substr($expression, $openParen + 1, $closeParen - $openParen - 1));
    }

    private static function evaluateScalar(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): mixed {
        $nodeIds = self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, false);
        if ([] === $nodeIds) {
            return '';
        }
        $node = DomRegistry::entry($nodeIds[0]);
        if (null === $node) {
            return '';
        }
        // Attr string-value is the attribute value (XPath 1.0 / php-src xpath.c; #19352).
        if (VmDom::isElement($node) || VmDom::isTextNode($node) || VmDom::isAttr($node)) {
            return VmDom::readNodeValue($node) ?? '';
        }

        return '';
    }

    private static function booleanize(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return 0.0 !== (float) $value && 0 !== $value;
        }
        if (is_string($value)) {
            return '' !== $value;
        }
        if (is_array($value)) {
            return [] !== $value;
        }

        return false;
    }

    /**
     * Top-level php:function() / php:functionString() evaluate (#19331, php-src xpath.c).
     */
    private static function tryEvaluatePhpFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS
    ): ?Variable {
        if (!preg_match('~^([A-Za-z_][\w]*)\:(function(?:String)?)\(~i', $expression, $prefixMatch)) {
            return null;
        }
        self::ensureXPath($xpath);
        $state = DomRegistry::state($xpath);
        if ($registerNodeNS && null !== $contextNode) {
            self::registerContextNodeNamespaces($xpath, $contextNode);
        }
        $prefix = $prefixMatch[1];
        $callName = strtolower($prefixMatch[2]);
        $nsUri = $state->xpathNamespaces[$prefix] ?? null;
        if (null === $nsUri) {
            // Zend emits a libxml warning and returns false for evaluate().
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);

            return $var;
        }
        if (DomConstants::PHP_XPATH_NS !== $nsUri) {
            throw new \DOMException('Invalid expression');
        }
        $openParen = strpos($expression, '(');
        if (false === $openParen) {
            return null;
        }
        $closeParen = self::findMatchingCloseParen($expression, $openParen);
        if (null === $closeParen || $closeParen !== \strlen($expression) - 1) {
            return null;
        }
        $argsStr = substr($expression, $openParen + 1, $closeParen - $openParen - 1);
        $argExprs = self::splitXPathCallArgs($argsStr);
        if ([] === $argExprs) {
            throw new \Error('Function name must be passed as the first argument');
        }
        $handlerName = self::resolvePhpFunctionHandlerName($ctx, $xpath, trim($argExprs[0]), $contextNode);
        if (!self::assertPhpFunctionAllowed($state, $handlerName)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);

            return $var;
        }
        $asString = 'functionstring' === $callName;
        $callArgs = [];
        for ($i = 1, $n = \count($argExprs); $i < $n; ++$i) {
            $callArgs[] = self::evaluatePhpFunctionArg($ctx, $xpath, trim($argExprs[$i]), $contextNode, $asString);
        }
        $callback = new Variable(Variable::TYPE_STRING);
        $callback->string($handlerName);
        $result = VmCallable::invoke($ctx, $callback, ...$callArgs);

        return self::coercePhpFunctionReturn($result);
    }

    /**
     * Top-level prefix:localName(...) for registerPhpFunctionNS() (#20119).
     */
    private static function tryEvaluateNamespacedPhpFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS
    ): ?Variable {
        if (!preg_match('~^([A-Za-z_][\w]*):([A-Za-z_][\w.-]*)\(~', $expression, $prefixMatch)) {
            return null;
        }
        // php:function / php:functionString handled elsewhere.
        if (0 === strcasecmp($prefixMatch[2], 'function') || 0 === strcasecmp($prefixMatch[2], 'functionString')) {
            return null;
        }
        self::ensureXPath($xpath);
        if ($registerNodeNS && null !== $contextNode) {
            self::registerContextNodeNamespaces($xpath, $contextNode);
        }
        $openParen = strpos($expression, '(');
        if (false === $openParen) {
            return null;
        }
        $closeParen = self::findMatchingCloseParen($expression, $openParen);
        if (null === $closeParen || $closeParen !== \strlen($expression) - 1) {
            return null;
        }
        $argsStr = substr($expression, $openParen + 1, $closeParen - $openParen - 1);

        return self::invokeNamespacedPhpFunction(
            $ctx,
            $xpath,
            $prefixMatch[1],
            $prefixMatch[2],
            $argsStr,
            $contextNode
        );
    }

    /**
     * //tag[prefix:fn(args)(= "lit")?] filtered by registerPhpFunctionNS (#20119).
     *
     * @return list<int>|null
     */
    private static function tryEvaluateNamespacedPhpFunctionPredicate(
        Context $ctx,
        ObjectEntry $xpath,
        ObjectEntry $context,
        string $expression,
        DomNodeState $state
    ): ?array {
        $compare = null;
        $matches = null;
        if (preg_match(
            '~^//([*\w][\w:-]*)\[([A-Za-z_][\w]*):([A-Za-z_][\w.-]*)\((.*)\)\s*=\s*(["\'])(.*?)\5\]$~s',
            $expression,
            $matches
        )) {
            $compare = $matches[6];
        } elseif (preg_match(
            '~^//([*\w][\w:-]*)\[([A-Za-z_][\w]*):([A-Za-z_][\w.-]*)\((.*)\)\]$~s',
            $expression,
            $matches
        )) {
            $compare = null;
        } else {
            return null;
        }

        $tag = $matches[1];
        $prefix = $matches[2];
        $localName = $matches[3];
        $argsStr = $matches[4];
        $nsUri = $state->xpathNamespaces[$prefix] ?? null;
        if (null === $nsUri || !isset($state->xpathPhpFunctionNs[$nsUri][$localName])) {
            // Unregistered — match libxml empty node-set rather than Invalid expression for query.
            return [];
        }

        $nodeIds = self::collectDescendantElements($context, $tag, $state->xpathNamespaces);
        $filtered = [];
        foreach ($nodeIds as $nodeId) {
            $element = DomRegistry::entry($nodeId);
            if (null === $element || !VmDom::isElement($element)) {
                continue;
            }
            $result = self::invokeNamespacedPhpFunction(
                $ctx,
                $xpath,
                $prefix,
                $localName,
                $argsStr,
                $element
            );
            if (null === $compare) {
                if (self::booleanizePhpFunctionResult($result)) {
                    $filtered[] = $nodeId;
                }
            } elseif ($result->toString() === $compare) {
                $filtered[] = $nodeId;
            }
        }

        return $filtered;
    }

    private static function invokeNamespacedPhpFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $prefix,
        string $localName,
        string $argsStr,
        ?ObjectEntry $contextNode
    ): Variable {
        $state = DomRegistry::state($xpath);
        $nsUri = $state->xpathNamespaces[$prefix] ?? null;
        if (null === $nsUri) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);

            return $var;
        }
        $callable = $state->xpathPhpFunctionNs[$nsUri][$localName] ?? null;
        if (null === $callable) {
            throw new \DOMException('Invalid expression');
        }
        $argExprs = self::splitXPathCallArgs($argsStr);
        $callArgs = [];
        foreach ($argExprs as $argExpr) {
            $callArgs[] = self::evaluatePhpFunctionArg($ctx, $xpath, trim($argExpr), $contextNode, false);
        }

        return self::coercePhpFunctionReturn(VmCallable::invoke($ctx, $callable, ...$callArgs));
    }

    private static function booleanizePhpFunctionResult(Variable $result): bool
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_NULL === $result->type) {
            return false;
        }
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }
        if (Variable::TYPE_STRING === $result->type) {
            return '' !== $result->toString();
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            return 0 !== $result->toInt();
        }
        if (Variable::TYPE_FLOAT === $result->type) {
            $n = $result->toFloat();

            return 0.0 !== $n && !is_nan($n);
        }

        return true;
    }

    private static function assertValidPhpFunctionName(string $name, bool $isArray): void
    {
        if ('' === $name || str_contains($name, "\0")) {
            throw new \ValueError($isArray
                ? 'DOMXPath::registerPhpFunctions(): Argument #1 ($restrict) must be an array containing valid callback names'
                : 'DOMXPath::registerPhpFunctions(): Argument #1 ($restrict) must be a valid callback name');
        }
    }

    private static function assertPhpFunctionAllowed(DomNodeState $state, string $handlerName): bool
    {
        if (DomConstants::XPATH_REG_FUNC_MODE_NONE === $state->xpathPhpFunctionsMode) {
            // php-src 8.2: warning + evaluate() returns false; do not throw for NONE.
            return false;
        }
        if (DomConstants::XPATH_REG_FUNC_MODE_ALL === $state->xpathPhpFunctionsMode) {
            return true;
        }
        if (!isset($state->xpathPhpFunctions[$handlerName])) {
            throw new \Error(sprintf("Not allowed to call handler '%s()'.", $handlerName));
        }

        return true;
    }

    private static function resolvePhpFunctionHandlerName(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): string {
        if (preg_match('~^"(.*)"$~s', $expression, $m) || preg_match("~^'(.*)'$~s", $expression, $m)) {
            return $m[1];
        }
        try {
            $value = self::evaluateScalar($ctx, $xpath, $expression, $contextNode);
        } catch (\DOMException) {
            throw new \TypeError('Handler name must be a string');
        }
        if (!is_string($value)) {
            throw new \TypeError('Handler name must be a string');
        }

        return $value;
    }

    private static function evaluatePhpFunctionArg(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $nodesetToString
    ): Variable {
        if (preg_match('~^"(.*)"$~s', $expression, $m) || preg_match("~^'(.*)'$~s", $expression, $m)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($m[1]);

            return $var;
        }
        if (self::isBooleanExpression($expression)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(self::evaluateBoolean($ctx, $xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isNumericExpression($expression)) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float(self::evaluateNumber($ctx, $xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isStringExpression($expression)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string(self::evaluateString($ctx, $xpath, $expression, $contextNode));

            return $var;
        }
        // Node-set argument — php:function passes DOMNode[]; functionString coerces to string-value
        // of the first node (php-src xpath_callbacks.c; #19331 / #19709).
        $nodeIds = self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, false);
        if ($nodesetToString) {
            $var = new Variable(Variable::TYPE_STRING);
            if ([] === $nodeIds) {
                $var->string('');

                return $var;
            }
            $node = DomRegistry::entry($nodeIds[0]);
            $var->string(null !== $node ? (VmDom::readNodeValue($node) ?? '') : '');

            return $var;
        }
        $ht = new HashTable();
        foreach ($nodeIds as $nodeId) {
            $node = DomRegistry::entry($nodeId);
            if (null === $node) {
                continue;
            }
            $obj = new Variable(Variable::TYPE_OBJECT);
            $obj->object($node);
            $ht->append($obj);
        }
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return $var;
    }

    private static function coercePhpFunctionReturn(Variable $result): Variable
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type
            || Variable::TYPE_STRING === $result->type
            || Variable::TYPE_INTEGER === $result->type
            || Variable::TYPE_FLOAT === $result->type
            || Variable::TYPE_NULL === $result->type
        ) {
            if (Variable::TYPE_INTEGER === $result->type) {
                // XPath numeric returns are floats in evaluate().
                $out = new Variable(Variable::TYPE_FLOAT);
                $out->float((float) $result->toInt());

                return $out;
            }

            return $result;
        }
        // Non-DOM object returns → string cast (php-src xpath_callbacks.c).
        $out = new Variable(Variable::TYPE_STRING);
        if (Variable::TYPE_OBJECT === $result->type) {
            throw new \TypeError('Only objects that are instances of DOM nodes can be converted to an XPath expression');
        }
        $out->string($result->toString());

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitXPathCallArgs(string $args): array
    {
        $args = trim($args);
        if ('' === $args) {
            return [];
        }
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = \strlen($args);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $args[$i];
            if (null !== $quote) {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                $buf .= $ch;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                $buf .= $ch;
                continue;
            }
            if (',' === $ch && 0 === $depth) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;

        return $parts;
    }

    private static function findMatchingCloseParen(string $expression, int $openParen): ?int
    {
        $depth = 0;
        $quote = null;
        $len = \strlen($expression);
        for ($i = $openParen; $i < $len; ++$i) {
            $ch = $expression[$i];
            if (null !== $quote) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $quote = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }
}
