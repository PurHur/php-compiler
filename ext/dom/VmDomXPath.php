<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/** DOMXPath evaluation engine (php-src ext/dom/xpath.c; #6066). */
final class VmDomXPath
{
    /** XPath 1.0 named axes (https://www.w3.org/TR/1999/REC-xpath-19991116/#axes; #31773). */
    private const TREE_AXES = [
        'ancestor' => true,
        'ancestor-or-self' => true,
        'attribute' => true,
        'child' => true,
        'descendant' => true,
        'descendant-or-self' => true,
        'following' => true,
        'following-sibling' => true,
        'namespace' => true,
        'parent' => true,
        'preceding' => true,
        'preceding-sibling' => true,
        'self' => true,
    ];

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

    /**
     * XPath 1.0 string-value of a node (libxml / php-src ext/dom/xpath.c).
     *
     * Living Dom\Element::$nodeValue is always null (#21034); element/document/
     * fragment string-value is descendant text (`textContent`). Attr/text/comment/
     * PI/namespace keep `nodeValue` (#21271, re-#20757/#20818).
     */
    private static function xpathStringValue(?ObjectEntry $node): string
    {
        if (null === $node || !DomRegistry::has($node)) {
            return '';
        }
        if (VmDom::isElement($node) || VmDom::isDocument($node) || VmDom::isDocumentFragment($node)) {
            return VmDom::readTextContent($node);
        }

        return VmDom::readNodeValue($node) ?? '';
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
        // php-src xpath.c → xmlXPathRegisterNs: empty/NULL prefix fails (cannot register default NS this way).
        if ('' === $prefix) {
            return false;
        }
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
        bool $registerNodeNS = true,
        string $failureMethod = 'query',
        ?Frame $frame = null
    ): Variable {
        try {
            $nodeIds = self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        } catch (\DOMException $e) {
            // Legacy DOMXPath: libxml E_WARNING + false; Dom\XPath throws (#22721 / php-src xpath.c).
            $legacy = self::tryLegacyLibxmlXPathFailure($ctx, $xpath, $failureMethod, $e, $frame);
            if (null !== $legacy) {
                return $legacy;
            }
            throw $e;
        }

        if (VmDom::prefersDomNodeList($xpath)) {
            return VmDom::createDomNodeList($ctx, $nodeIds);
        }

        return VmDom::createNodeList($ctx, $nodeIds);
    }

    public static function evaluate(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode = null,
        bool $registerNodeNS = true,
        ?Frame $frame = null
    ): Variable {
        $expression = trim($expression);
        try {
            $phpFn = self::tryEvaluatePhpFunction(
                $ctx,
                $xpath,
                $expression,
                $contextNode,
                $registerNodeNS,
                $frame
            );
            if (null !== $phpFn) {
                return $phpFn;
            }
            $nsFn = self::tryEvaluateNamespacedPhpFunction(
                $ctx,
                $xpath,
                $expression,
                $contextNode,
                $registerNodeNS,
                $frame
            );
            if (null !== $nsFn) {
                return $nsFn;
            }
            if (self::isBooleanExpression($expression)) {
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(self::evaluateBoolean($ctx, $xpath, $expression, $contextNode, $registerNodeNS));

                return $var;
            }
            if (self::isNumericExpression($expression)) {
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float(self::evaluateNumber($ctx, $xpath, $expression, $contextNode, $registerNodeNS));

                return $var;
            }
            if (self::isStringExpression($expression)) {
                $var = new Variable(Variable::TYPE_STRING);
                $var->string(self::evaluateString($ctx, $xpath, $expression, $contextNode, $registerNodeNS));

                return $var;
            }

            // Node-set path — keep warn method as evaluate (#22755).
            return self::query($ctx, $xpath, $expression, $contextNode, $registerNodeNS, 'evaluate', $frame);
        } catch (\DOMException $e) {
            // Legacy DOMXPath: libxml E_WARNING + false; Dom\XPath throws (#22721 / #22755).
            $legacy = self::tryLegacyLibxmlXPathFailure($ctx, $xpath, 'evaluate', $e, $frame);
            if (null !== $legacy) {
                return $legacy;
            }
            throw $e;
        }
    }

    /**
     * php-src ext/dom/xpath.c — xmlXPathEval failure on classic DOMXPath → E_WARNING + false.
     * Living Dom\XPath keeps DOMException (#20842).
     */
    private static function tryLegacyLibxmlXPathFailure(
        Context $ctx,
        ObjectEntry $xpath,
        string $method,
        \DOMException $e,
        ?Frame $frame = null
    ): ?Variable {
        if (VmDom::prefersDomNodeList($xpath)) {
            return null;
        }
        $message = $e->getMessage();
        // libxml xmlXPathCompOpEval unbound QName prefix (#23534) — keep full message.
        if ('Invalid expression' !== $message
            && 'Undefined namespace prefix' !== $message
            && 'Invalid number of arguments' !== $message
            && !str_starts_with($message, 'xmlXPathCompOpEval:')) {
            return null;
        }
        $ctx->errors->triggerError(
            sprintf('DOMXPath::%s(): %s', $method, $message),
            ErrorReporter::E_WARNING,
            null !== $frame && '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $ctx,
            $frame
        );
        $false = new Variable(Variable::TYPE_BOOLEAN);
        $false->bool(false);

        return $false;
    }

    /**
     * Reject XPath expressions with obvious syntax errors that the hand-rolled
     * evaluator would silently swallow as empty results (#22721 regression).
     * libxml/php-src would report xmlXPathEval failure → E_WARNING + false.
     */
    private static function validateXPathSyntax(string $expression): void
    {
        if (substr_count($expression, '[') !== substr_count($expression, ']')) {
            throw new \DOMException('Invalid expression');
        }
        if (substr_count($expression, '(') !== substr_count($expression, ')')) {
            throw new \DOMException('Invalid expression');
        }
        if (str_contains($expression, '///')) {
            throw new \DOMException('Invalid expression');
        }
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

        // php-src: if (!nodep) nodep = xmlDocGetRootElement(docp) (#20842).
        $context = self::resolveXPathContext($document, $contextNode);
        if (!VmDom::isDomNode($context)) {
            throw new \TypeError('DOMXPath::query(): Argument #2 ($context) must be of type ?DOMNode, DOMNode given');
        }

        $savedNamespaces = null;
        if ($registerNodeNS) {
            // Temporary in-scope merge — do not permanently pollute registerNamespace() map (#20842).
            $savedNamespaces = $state->xpathNamespaces;
            $merged = $savedNamespaces;
            foreach (self::collectInScopeNamespaces($context) as $prefix => $uri) {
                if ('' === $prefix) {
                    continue; // default NS is not an XPath prefix
                }
                $merged[$prefix] = $uri;
            }
            $state->xpathNamespaces = $merged;
        }
        try {
            return self::evaluateNodeSetBody($ctx, $xpath, $expression, $context, $registerNodeNS, $state);
        } finally {
            if (null !== $savedNamespaces) {
                $state->xpathNamespaces = $savedNamespaces;
            }
        }
    }

    /**
     * php-src xpath.c — null context uses documentElement for both eval node and in-scope NS (#20842).
     */
    private static function resolveXPathContext(ObjectEntry $document, ?ObjectEntry $contextNode): ObjectEntry
    {
        if (null !== $contextNode) {
            return $contextNode;
        }
        $rootVar = $document->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $root = $rootVar->toObject();
            if (VmDom::isElement($root)) {
                return $root;
            }
        }

        return $document;
    }

    /**
     * @return list<int>
     */
    private static function evaluateNodeSetBody(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ObjectEntry $context,
        bool $registerNodeNS,
        DomNodeState $state
    ): array {
        self::validateXPathSyntax($expression);

        // Union: a|b — document order, unique (#20257; C14N nodeset + attrs).
        if (str_contains($expression, '|')) {
            return self::evaluateUnionNodeSet($ctx, $xpath, $expression, $context, $registerNodeNS);
        }

        // XPath 1.0 id(object) — document ID table via getElementById (#23323).
        if (preg_match('~^id\(~i', $expression)) {
            return self::evaluateIdFunction($ctx, $xpath, $expression, $context, $registerNodeNS);
        }

        // Relative location paths: `.` / `..` / `.//…` / `./…` (XPath 1.0; #20257, #31773).
        if ('.' === $expression) {
            return DomRegistry::has($context) ? [$context->id] : [];
        }
        if ('..' === $expression) {
            return self::collectMatchingAlongAxis($context, '..', $state->xpathNamespaces, $ctx, $xpath);
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

        // //… — absolute descendant axis from the document root (libxml/php-src xpath.c; #21125).
        // Leading `//` ignores the context node (unlike `.//…`). Must run before `/…`
        // — otherwise `//text()` is eaten as `/` + `/text()`.
        if (str_starts_with($expression, '//')) {
            $document = self::ownerDocumentOrSelf($context);
            if (null === $document) {
                return [];
            }

            return self::evaluateDescendantPath(
                $document,
                substr($expression, 2),
                $state->xpathNamespaces,
                $ctx,
                $xpath
            );
        }

        // Absolute /… from the document root (#19709).
        if (str_starts_with($expression, '/')) {
            return self::evaluateAbsolutePath($context, substr($expression, 1), $state->xpathNamespaces, $ctx, $xpath);
        }

        // Relative multi-segment child path: wrap/a, a/text() (#20456).
        if (str_contains($expression, '/')) {
            return self::evaluateChildAxisPath($context, $expression, $state->xpathNamespaces, $ctx, $xpath);
        }

        // Child / named-axis step: tag / * / text() / child::* / following-sibling::* (#20456, #31773).
        if (self::looksLikePathSegment($expression)) {
            return self::collectMatchingAlongAxis($context, $expression, $state->xpathNamespaces, $ctx, $xpath);
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
     * XPath 1.0 id(object) → node-set from the document ID table (#23323).
     *
     * String arg: whitespace-separated tokens (libxml drops the first token when the
     * string has leading whitespace — match Zend/php-src). Node-set arg: union of
     * id(string-value) for each node.
     *
     * @return list<int>
     */
    private static function evaluateIdFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ObjectEntry $context,
        bool $registerNodeNS
    ): array {
        $args = self::wrappedFunctionArgs($expression, 'id');
        if (null === $args || 1 !== \count($args)) {
            throw new \DOMException('Invalid expression');
        }
        $document = self::ownerDocumentOrSelf($context);
        if (null === $document) {
            $state = DomRegistry::state($xpath);
            $document = DomRegistry::entry($state->xpathDocumentId ?? 0);
        }
        if (null === $document || !VmDom::isDocument($document)) {
            return [];
        }

        $argValue = self::evaluateToMixed($ctx, $xpath, trim($args[0]), $context, $registerNodeNS);
        if (is_array($argValue)) {
            $seen = [];
            $ids = [];
            foreach ($argValue as $nodeId) {
                $node = DomRegistry::entry((int) $nodeId);
                if (null === $node) {
                    continue;
                }
                foreach (self::elementIdsFromIdString($document, self::xpathStringValue($node)) as $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return self::elementIdsFromIdString($document, self::stringify($argValue));
    }

    /**
     * Split an id() string into tokens and resolve via getElementById (#23323).
     *
     * @return list<int>
     */
    private static function elementIdsFromIdString(ObjectEntry $document, string $str): array
    {
        // libxml xmlXPathIdFunction: leading whitespace skips the first token (Zend parity).
        $skipFirst = 1 === preg_match('/^\s/u', $str);
        $tokens = preg_split('/\s+/u', trim($str), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens)) {
            $tokens = [];
        }
        if ($skipFirst && [] !== $tokens) {
            array_shift($tokens);
        }
        $seen = [];
        $ids = [];
        foreach ($tokens as $token) {
            $element = VmDom::getElementById($document, $token);
            if (null === $element || isset($seen[$element->id])) {
                continue;
            }
            $seen[$element->id] = true;
            $ids[] = $element->id;
        }

        return $ids;
    }

    /**
     * XPath 1.0 lang(string) — xml:lang on context or nearest ancestor (#23323).
     */
    private static function evaluateLangFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS
    ): bool {
        $args = self::wrappedFunctionArgs($expression, 'lang');
        if (null === $args || 1 !== \count($args)) {
            throw new \DOMException('Invalid expression');
        }
        $want = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
        $state = DomRegistry::state($xpath);
        $document = DomRegistry::entry($state->xpathDocumentId ?? 0);
        if (null === $document || !VmDom::isDocument($document)) {
            return false;
        }
        $context = self::resolveXPathContext($document, $contextNode);
        $xmlLang = self::nearestXmlLang($context);
        if (null === $xmlLang) {
            return false;
        }

        return self::xpathLangMatches($xmlLang, $want);
    }

    /** Walk context → ancestors for xml:lang (XML namespace or xml:lang qName). */
    private static function nearestXmlLang(ObjectEntry $context): ?string
    {
        $current = $context;
        while (DomRegistry::has($current)) {
            if (VmDom::isElement($current)) {
                if (VmDom::hasAttributeNS($current, DomConstants::XML_NS_URI, 'lang')) {
                    return VmDom::getAttributeNS($current, DomConstants::XML_NS_URI, 'lang');
                }
                // Serialized xml:lang may live as a prefixed attribute without NS map yet.
                $state = DomRegistry::state($current);
                if (isset($state->attributes['xml:lang'])) {
                    return $state->attributes['xml:lang'];
                }
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

        return null;
    }

    /**
     * XPath 1.0 lang() match: case-insensitive equality or prefix before '-'.
     */
    private static function xpathLangMatches(string $xmlLang, string $testLang): bool
    {
        $xmlLang = strtolower($xmlLang);
        $testLang = strtolower($testLang);
        if ($xmlLang === $testLang) {
            return true;
        }
        $prefixLen = \strlen($testLang);

        return \strlen($xmlLang) > $prefixLen
            && str_starts_with($xmlLang, $testLang)
            && '-' === $xmlLang[$prefixLen];
    }

    /**
     * `.//inner` — descendant axis from context (excludes context self for element tests; #20257, #20456).
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
        // .//@attr / .//tag/@attr — attribute axis under context descendants.
        $attrIds = self::tryEvaluateAttributeAxis($ctx, $context, '//'.$inner, $namespaces);
        if (null !== $attrIds) {
            return $attrIds;
        }
        return self::evaluateDescendantPath($context, $inner, $namespaces, $ctx, $xpath);
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
        // //@attr — every attribute with that name in the document (absolute //; #21125).
        if (preg_match('~^//@([\w.-]+)$~', $expression, $matches)) {
            $document = self::ownerDocumentOrSelf($context) ?? $context;

            return self::collectDescendantAttributeNodes($ctx, $document, $matches[1], $namespaces, null, null);
        }
        // //tag[@attr='v']/@name — predicate then attribute axis (#21148).
        // Unquoted numeric RHS uses XPath 1.0 number equality (#24333).
        if (preg_match(
            '~^//([*\w][\w:-]*)\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\]/@([\w.-]+)$~',
            $expression,
            $matches
        )) {
            $document = self::ownerDocumentOrSelf($context) ?? $context;
            $elementIds = self::collectDescendantElements($document, $matches[1], $namespaces);
            $numeric = isset($matches[4]) && '' !== $matches[4];
            $attrValue = $numeric ? $matches[4] : ($matches[3] ?? '');
            $elementIds = array_values(array_filter(
                $elementIds,
                static fn (int $id): bool => self::elementAttributeEquals(
                    DomRegistry::entry($id),
                    $matches[2],
                    $attrValue,
                    $namespaces,
                    $numeric
                )
            ));

            return self::attributeIdsFromElementIds($ctx, $elementIds, $matches[5], $namespaces);
        }
        // //tag/@attr or //tag[n]/@attr — document-scoped (#21125).
        if (preg_match('~^//([*\w][\w:-]*)(?:\[(\d+)\])?/@([\w.-]+)$~', $expression, $matches)) {
            $position = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : null;
            $document = self::ownerDocumentOrSelf($context) ?? $context;

            return self::collectDescendantAttributeNodes(
                $ctx,
                $document,
                $matches[3],
                $namespaces,
                $matches[1],
                $position
            );
        }
        // /seg/.../@attr — absolute element path then attribute step (#21148).
        if (preg_match('~^/(.+)/@([\w.-]+)$~', $expression, $matches)) {
            $elementIds = self::evaluateAbsolutePath($context, $matches[1], $namespaces);

            return self::attributeIdsFromElementIds($ctx, $elementIds, $matches[2], $namespaces);
        }

        return null;
    }

    /**
     * @param list<int>             $elementIds
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function attributeIdsFromElementIds(
        Context $ctx,
        array $elementIds,
        string $attrName,
        array $namespaces
    ): array {
        $attrIds = [];
        foreach ($elementIds as $elementId) {
            $element = DomRegistry::entry($elementId);
            if (null === $element || !VmDom::isElement($element)) {
                continue;
            }
            $attr = self::attributeNodeFromElement($ctx, $element, $attrName, $namespaces);
            if (null === $attr) {
                continue;
            }
            $attrIds[] = $attr->id;
        }

        return $attrIds;
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
        // Prefer temporary merge in evaluateNodeSet (#20842). Kept for php:function paths.
        foreach (self::collectInScopeNamespaces($context) as $prefix => $uri) {
            if ('' === $prefix) {
                continue;
            }
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
        // Wildcard `*` — every element (DOM getElementsByTagName semantics).
        if ('*' === $localName) {
            return VmDom::collectElementsByTagName($context, '*');
        }
        // Unprefixed name test: null namespace URI only (XPath 1.0; #21125).
        // DOM getElementsByTagName matches local name across namespaces — too broad.
        $ids = VmDom::collectElementsByTagName($context, $localName);

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => self::elementMatchesTag(
                DomRegistry::entry($id),
                $localName,
                null
            )
        ));
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
     * Absolute location path from the document root (XPath 1.0 / php-src xpath.c; #19709, #20456).
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
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $document = VmDom::isDocument($context)
            ? $context
            : VmDom::ownerDocumentEntry($context);
        if (null === $document || !VmDom::isDocument($document)) {
            return [];
        }
        $path = trim($path);
        if ('' === $path) {
            return [];
        }

        return self::evaluateChildAxisPath($document, $path, $namespaces, $ctx, $xpath);
    }

    /**
     * `//path` — first segment is descendant axis, remaining segments are child (#20456).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function evaluateDescendantPath(
        ObjectEntry $context,
        string $path,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $segments = self::splitLocationPath($path);
        if ([] === $segments) {
            throw new \DOMException('Invalid expression');
        }
        $firstSplit = self::splitAxisAndTest($segments[0]);
        // `//following-sibling::*` ≡ descendant-or-self::node()/following-sibling::* (#31773).
        // `//*[last()]` / `//a[position()=N]` ≡ descendant-or-self::node()/child::…[pred]
        // with proximity position **per parent**, not the flattened descendant set (#31923).
        $useDescendantOrSelfThenStep = 'child' !== $firstSplit['axis']
            || null !== self::parsePathSegment($firstSplit['testSegment'])['positionPred'];
        if ($useDescendantOrSelfThenStep) {
            return self::sortNodeIdsByDocumentOrder(
                $context,
                self::walkAxisSegments(
                    self::descendantOrSelfNodeIds($context),
                    $segments,
                    $namespaces,
                    $ctx,
                    $xpath
                )
            );
        }
        $currentIds = self::collectMatchingDescendants($context, $segments[0], $namespaces, $ctx, $xpath);

        return self::walkAxisSegments($currentIds, array_slice($segments, 1), $namespaces, $ctx, $xpath);
    }

    /**
     * Child-axis location path from $start (absolute from document or relative from context).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function evaluateChildAxisPath(
        ObjectEntry $start,
        string $path,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $segments = self::splitLocationPath($path);
        if ([] === $segments) {
            return [];
        }

        return self::walkAxisSegments([$start->id], $segments, $namespaces, $ctx, $xpath);
    }

    /**
     * Reorder a node-set into XPath document order (libxml xmlXPathNodeSet; #31923).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function sortNodeIdsByDocumentOrder(ObjectEntry $context, array $ids): array
    {
        if (\count($ids) <= 1) {
            return $ids;
        }
        $document = self::ownerDocumentOrSelf($context);
        if (null === $document) {
            return $ids;
        }
        $rank = array_flip(self::documentOrderIds($document));
        usort(
            $ids,
            static function (int $a, int $b) use ($rank): int {
                return ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX);
            }
        );

        return $ids;
    }

    /**
     * Apply location-path steps from each context node (document order, unique; #31773).
     *
     * @param list<int>             $currentIds
     * @param list<string>          $segments
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function walkAxisSegments(
        array $currentIds,
        array $segments,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        foreach ($segments as $segment) {
            $nextIds = [];
            $seen = [];
            foreach ($currentIds as $id) {
                $node = DomRegistry::entry($id);
                if (null === $node) {
                    continue;
                }
                foreach (self::collectMatchingAlongAxis($node, $segment, $namespaces, $ctx, $xpath) as $nextId) {
                    if (isset($seen[$nextId])) {
                        continue;
                    }
                    $seen[$nextId] = true;
                    $nextIds[] = $nextId;
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
     * @return list<string>
     */
    private static function splitLocationPath(string $path): array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' !== $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private static function looksLikePathSegment(string $expression): bool
    {
        if ('..' === $expression || '.' === $expression) {
            return true;
        }
        $candidate = self::splitAxisAndTest($expression)['testSegment'];
        $parsed = self::parsePathSegment($candidate);
        if (self::isNodeTypeTestName($parsed['test'])) {
            return true;
        }
        if (null !== ($parsed['generalPred'] ?? null)) {
            return true;
        }

        return (bool) preg_match(
            '~^[*\w][\w:-]*(?:\[(?:@[^\]]+|[\d]+|(?:local-name|name|namespace-uri)\(\)\s*=\s*["\'][^"\']*["\']|text\(\)\s*=\s*["\'].*?["\']|contains\(\s*text\(\)\s*,\s*["\'].*?["\']\s*\))\])?$~is',
            $candidate
        );
    }

    /**
     * @return array{
     *     test: string,
     *     attr: ?string,
     *     attrValue: string,
     *     attrNumeric: bool,
     *     positionPred: null|array{op: string, rhs: int|string},
     *     fnPred: ?string,
     *     fnPredValue: string
     * }
     */
    private static function parsePathSegment(string $segment): array
    {
        // [local-name()="x"] / [name()='a:x'] / [namespace-uri()="urn:a"] (#21125).
        if (preg_match(
            '~^(.+?)\[(local-name|name|namespace-uri)\(\)\s*=\s*(["\'])(.*?)\3\]$~i',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => null,
                'fnPred' => strtolower($matches[2]),
                'fnPredValue' => $matches[4],
            ];
        }
        // [text()='…'] / [text()="…"] — child text node equality (#22008).
        if (preg_match(
            '~^(.+?)\[text\(\)\s*=\s*(["\'])(.*?)\2\]$~s',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => null,
                'fnPred' => 'text-eq',
                'fnPredValue' => $matches[3],
            ];
        }
        // [contains(text(),'…')] — first child text string-value (#22008).
        if (preg_match(
            '~^(.+?)\[contains\(\s*text\(\)\s*,\s*(["\'])(.*?)\2\s*\)\]$~is',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => null,
                'fnPred' => 'contains-text',
                'fnPredValue' => $matches[3],
            ];
        }
        // [@attr] — attribute existence; XPath 1.0 node-set truth (#30439).
        if (preg_match(
            '~^(.+?)\[@([a-zA-Z_][\w:.\\-]*)\]$~',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => $matches[2],
                'attrValue' => '',
                'attrNumeric' => false,
                'attrExists' => true,
                'attrOp' => null,
                'positionPred' => null,
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        // [@attr op N] — numeric comparison (>, >=, <, <=, !=); XPath 1.0 (#30439).
        if (preg_match(
            '~^(.+?)\[@([^\]=><!]+?)\s*(!=|<=|>=|<|>)\s*([+-]?(?:\d+\.?\d*|\.\d+))\]$~',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => $matches[2],
                'attrValue' => $matches[4],
                'attrNumeric' => true,
                'attrExists' => false,
                'attrOp' => $matches[3],
                'positionPred' => null,
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        // [@attr=N] — unquoted numeric literal; XPath 1.0 number equality (#24333).
        if (preg_match(
            '~^(.+?)\[@([^\]=]+)=([+-]?(?:\d+\.?\d*|\.\d+))\]$~',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => $matches[2],
                'attrValue' => $matches[3],
                'attrNumeric' => true,
                'attrExists' => false,
                'attrOp' => null,
                'positionPred' => null,
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        // [last()] — context size (#25083; XPath 1.0 ≡ [position()=last()]).
        if (preg_match('~^(.+?)\[last\(\)\]$~i', $segment, $matches)) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => ['op' => '=', 'rhs' => 'last'],
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        // [position()=last()] / [position()>last()] / … (#25083).
        if (preg_match(
            '~^(.+?)\[position\(\)\s*(=|!=|<=|>=|<|>)\s*last\(\)\]$~i',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => ['op' => $matches[2], 'rhs' => 'last'],
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        // [position()=N] / [position()>N] / … (#25083; [n] ≡ [position()=n]).
        if (preg_match(
            '~^(.+?)\[position\(\)\s*(=|!=|<=|>=|<|>)\s*([+-]?\d+)\]$~i',
            $segment,
            $matches
        )) {
            return [
                'test' => $matches[1],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => ['op' => $matches[2], 'rhs' => (int) $matches[3]],
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }
        if (preg_match(
            '~^(.+?)(?:\[(?:@([^\]=]+)=["\']([^"\']*)["\']|(\d+))\])?$~',
            $segment,
            $matches
        )) {
            $positionPred = null;
            if (isset($matches[4]) && '' !== $matches[4]) {
                $positionPred = ['op' => '=', 'rhs' => (int) $matches[4]];
            }
            $attr = isset($matches[2]) && '' !== $matches[2] ? $matches[2] : null;
            $test = $matches[1];
            // Catch-all is optional-predicate, so `a[@id or @class]` is eaten as a tag name.
            // Split a trailing `[pred]` into a general boolean predicate (#32050).
            if (null === $attr && null === $positionPred && str_contains($test, '[')) {
                $split = self::splitTrailingPredicate($test);
                if (null !== $split) {
                    return [
                        'test' => $split['test'],
                        'attr' => null,
                        'attrValue' => '',
                        'attrNumeric' => false,
                        'positionPred' => null,
                        'fnPred' => null,
                        'fnPredValue' => '',
                        'generalPred' => $split['pred'],
                    ];
                }
            }

            return [
                'test' => $test,
                'attr' => $attr,
                'attrValue' => $matches[3] ?? '',
                'attrNumeric' => false,
                'positionPred' => $positionPred,
                'fnPred' => null,
                'fnPredValue' => '',
            ];
        }

        $split = self::splitTrailingPredicate($segment);
        if (null !== $split) {
            return [
                'test' => $split['test'],
                'attr' => null,
                'attrValue' => '',
                'attrNumeric' => false,
                'positionPred' => null,
                'fnPred' => null,
                'fnPredValue' => '',
                'generalPred' => $split['pred'],
            ];
        }

        return [
            'test' => $segment,
            'attr' => null,
            'attrValue' => '',
            'attrNumeric' => false,
            'positionPred' => null,
            'fnPred' => null,
            'fnPredValue' => '',
        ];
    }

    /**
     * Split `test[pred]` from the last balanced `[…]` (#32050).
     *
     * @return array{test: string, pred: string}|null
     */
    private static function splitTrailingPredicate(string $segment): ?array
    {
        $len = \strlen($segment);
        if ($len < 3 || ']' !== $segment[$len - 1]) {
            return null;
        }
        $depth = 0;
        $quote = null;
        for ($i = $len - 1; $i >= 0; --$i) {
            $ch = $segment[$i];
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
            if (']' === $ch) {
                ++$depth;
                continue;
            }
            if ('[' !== $ch) {
                continue;
            }
            --$depth;
            if (0 !== $depth) {
                continue;
            }
            $test = substr($segment, 0, $i);
            $pred = trim(substr($segment, $i + 1, $len - $i - 2));
            if ('' === $test || '' === $pred) {
                return null;
            }

            return ['test' => $test, 'pred' => $pred];
        }

        return null;
    }

    private static function isNodeTypeTestName(string $test): bool
    {
        return 'node()' === $test
            || 'text()' === $test
            || 'comment()' === $test
            || 'processing-instruction()' === $test
            || (bool) preg_match('~^processing-instruction\(([\'"])([^\'"]*)\1\)$~', $test);
    }

    /**
     * XPath `node()` principal node types in a content model (not attr / namespace / document).
     */
    private static function isXPathContentNode(ObjectEntry $node): bool
    {
        return VmDom::isElement($node)
            || VmDom::isTextOrCdataNode($node)
            || VmDom::isCommentNode($node)
            || VmDom::isProcessingInstruction($node);
    }

    /** XPath 1.0 `node()` — any node except attribute and namespace (#31773). */
    private static function isXPathAnyNode(ObjectEntry $node): bool
    {
        return self::isXPathContentNode($node)
            || VmDom::isDocument($node)
            || VmDom::isDocumentFragment($node)
            || VmDom::isDocumentType($node)
            || VmDom::isEntityReference($node);
    }

    /**
     * @param array<string, string> $namespaces
     */
    private static function nodeMatchesPathTest(
        ObjectEntry $node,
        string $test,
        array $namespaces
    ): bool {
        $test = trim($test);
        if (VmDom::isAttr($node)) {
            return self::attributeMatchesPathTest($node, $test, $namespaces);
        }
        if ('node()' === $test) {
            return self::isXPathAnyNode($node);
        }
        if ('text()' === $test) {
            return VmDom::isTextOrCdataNode($node);
        }
        if ('comment()' === $test) {
            return VmDom::isCommentNode($node);
        }
        if ('processing-instruction()' === $test) {
            return VmDom::isProcessingInstruction($node);
        }
        if (preg_match('~^processing-instruction\(([\'"])([^\'"]*)\1\)$~', $test, $matches)) {
            return VmDom::isProcessingInstruction($node)
                && DomRegistry::state($node)->nodeName === $matches[2];
        }
        if (!VmDom::isElement($node)) {
            return false;
        }
        [$localName, $namespaceUri] = self::resolveQName($test, $namespaces);

        return self::elementMatchesTag($node, $localName, $namespaceUri);
    }

    /**
     * Apply optional [@attr='v'] / [@attr=N] / [n] / [position()…] / [last()] /
     * [local-name()="…"] / [text()=…] predicates
     * (#19456, #20456, #21125, #22008, #24333, #25083).
     *
     * @param list<int>                                  $nodeIds
     * @param array<string, string>                      $namespaces
     * @param null|array{op: string, rhs: int|string}    $positionPred
     *
     * @return list<int>
     */
    private static function applyPathSegmentPredicates(
        array $nodeIds,
        ?string $attr,
        string $attrValue,
        ?array $positionPred,
        array $namespaces,
        ?string $fnPred = null,
        string $fnPredValue = '',
        bool $attrNumeric = false,
        bool $attrExists = false,
        ?string $attrOp = null,
        ?string $generalPred = null,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        if (null !== $generalPred && null !== $ctx && null !== $xpath) {
            $nodeIds = array_values(array_filter(
                $nodeIds,
                static function (int $id) use ($ctx, $xpath, $generalPred): bool {
                    $node = DomRegistry::entry($id);
                    if (null === $node) {
                        return false;
                    }

                    return self::booleanize(
                        self::evaluateToMixed($ctx, $xpath, $generalPred, $node, false)
                    );
                }
            ));
        }
        if (null !== $attr) {
            if ($attrExists) {
                $nodeIds = array_values(array_filter(
                    $nodeIds,
                    static fn (int $id): bool => self::elementHasAttribute(
                        DomRegistry::entry($id),
                        $attr,
                        $namespaces
                    )
                ));
            } elseif (null !== $attrOp) {
                $nodeIds = array_values(array_filter(
                    $nodeIds,
                    static fn (int $id): bool => self::elementAttributeCompare(
                        DomRegistry::entry($id),
                        $attr,
                        $attrOp,
                        $attrValue,
                        $namespaces
                    )
                ));
            } else {
                $nodeIds = array_values(array_filter(
                    $nodeIds,
                    static fn (int $id): bool => self::elementAttributeEquals(
                        DomRegistry::entry($id),
                        $attr,
                        $attrValue,
                        $namespaces,
                        $attrNumeric
                    )
                ));
            }
        }
        if (null !== $fnPred) {
            $nodeIds = array_values(array_filter(
                $nodeIds,
                static fn (int $id): bool => self::elementMatchesNameFunctionPredicate(
                    DomRegistry::entry($id),
                    $fnPred,
                    $fnPredValue
                )
            ));
        }
        if (null !== $positionPred) {
            return self::filterNodeIdsByPositionPredicate($nodeIds, $positionPred);
        }

        return $nodeIds;
    }

    /**
     * XPath 1.0 proximity-position filter over a document-order node-set (#25083).
     *
     * @param list<int>                               $nodeIds
     * @param array{op: string, rhs: int|string}      $positionPred
     *
     * @return list<int>
     */
    private static function filterNodeIdsByPositionPredicate(array $nodeIds, array $positionPred): array
    {
        $last = \count($nodeIds);
        if (0 === $last) {
            return [];
        }
        $rhs = 'last' === $positionPred['rhs'] ? $last : (int) $positionPred['rhs'];
        $op = $positionPred['op'];
        $out = [];
        foreach ($nodeIds as $i => $id) {
            $pos = $i + 1;
            if (self::xpathPositionCompare($pos, $op, $rhs)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** XPath 1.0 number comparison for position()/last() predicates (#25083). */
    private static function xpathPositionCompare(int $pos, string $op, int $rhs): bool
    {
        return match ($op) {
            '=' => $pos === $rhs,
            '!=' => $pos !== $rhs,
            '<' => $pos < $rhs,
            '>' => $pos > $rhs,
            '<=' => $pos <= $rhs,
            '>=' => $pos >= $rhs,
            default => false,
        };
    }

    /**
     * XPath 1.0 name()/local-name()/namespace-uri()/text() predicates (#21125, #22008).
     */
    private static function elementMatchesNameFunctionPredicate(
        ?ObjectEntry $node,
        string $fnPred,
        string $expected
    ): bool {
        if (null === $node || !DomRegistry::has($node)) {
            return false;
        }
        if ('local-name' === $fnPred) {
            return VmDom::readLocalName($node) === $expected;
        }
        if ('name' === $fnPred) {
            return (DomRegistry::state($node)->nodeName ?? '') === $expected;
        }
        if ('namespace-uri' === $fnPred) {
            return (VmDom::readNamespaceUri($node) ?? '') === $expected;
        }
        // text()='…' — any child text/cdata string-value equals expected (XPath 1.0).
        if ('text-eq' === $fnPred) {
            return self::elementHasChildTextEqual($node, $expected);
        }
        // contains(text(), '…') — string-value of first child text/cdata node.
        if ('contains-text' === $fnPred) {
            return str_contains(self::firstChildTextStringValue($node), $expected);
        }

        return false;
    }

    /** @return list<ObjectEntry> child text/cdata nodes in document order */
    private static function childTextNodes(ObjectEntry $node): array
    {
        $out = [];
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child && VmDom::isTextOrCdataNode($child)) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private static function elementHasChildTextEqual(ObjectEntry $node, string $expected): bool
    {
        foreach (self::childTextNodes($node) as $text) {
            if (self::xpathStringValue($text) === $expected) {
                return true;
            }
        }

        return false;
    }

    private static function firstChildTextStringValue(ObjectEntry $node): string
    {
        $texts = self::childTextNodes($node);
        if ([] === $texts) {
            return '';
        }

        return self::xpathStringValue($texts[0]);
    }

    /**
     * Split `axis::nodetest` / `..` / `.` from a location step (#31773).
     *
     * @return array{axis: string, testSegment: string}
     */
    private static function splitAxisAndTest(string $segment): array
    {
        $segment = trim($segment);
        if ('..' === $segment) {
            return ['axis' => 'parent', 'testSegment' => 'node()'];
        }
        if ('.' === $segment) {
            return ['axis' => 'self', 'testSegment' => 'node()'];
        }
        // Abbreviated attribute axis: @* / @name / @prefix:local (#32003).
        if (preg_match('~^@(\*|(?:[\w.-]+:)?[\w.-]+)$~', $segment, $attrAbbrev)) {
            return ['axis' => 'attribute', 'testSegment' => $attrAbbrev[1]];
        }
        if (preg_match(
            '~^(ancestor-or-self|descendant-or-self|following-sibling|preceding-sibling|ancestor|attribute|child|descendant|following|namespace|parent|preceding|self)::(.*)$~is',
            $segment,
            $matches
        )) {
            $test = trim($matches[2]);
            if ('' === $test) {
                throw new \DOMException('Invalid expression');
            }

            $axis = strtolower($matches[1]);
            if (!isset(self::TREE_AXES[$axis])) {
                return ['axis' => 'child', 'testSegment' => $segment];
            }

            return ['axis' => $axis, 'testSegment' => $test];
        }

        return ['axis' => 'child', 'testSegment' => $segment];
    }

    /**
     * Collect nodes on the step's axis matching the node test + predicates (#31773).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectMatchingAlongAxis(
        ObjectEntry $context,
        string $segment,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $split = self::splitAxisAndTest($segment);
        $axis = $split['axis'];
        $parsed = self::parsePathSegment($split['testSegment']);
        // namespace::* full expressions stay on tryEvaluateNamespaceAxis (#20097 / #20170).
        if ('namespace' === $axis) {
            return [];
        }
        $ids = [];
        foreach (self::collectAxisNodes($context, $axis) as $node) {
            if (self::nodeMatchesPathTest($node, $parsed['test'], $namespaces)) {
                $ids[] = $node->id;
            }
        }

        return self::applyPathSegmentPredicates(
            $ids,
            $parsed['attr'],
            $parsed['attrValue'],
            $parsed['positionPred'],
            $namespaces,
            $parsed['fnPred'],
            $parsed['fnPredValue'],
            $parsed['attrNumeric'],
            $parsed['attrExists'] ?? false,
            $parsed['attrOp'] ?? null,
            $parsed['generalPred'] ?? null,
            $ctx,
            $xpath
        );
    }

    /**
     * @return list<ObjectEntry>
     */
    private static function collectAxisNodes(ObjectEntry $context, string $axis): array
    {
        return match ($axis) {
            'child' => self::childAxisNodes($context),
            'parent' => self::parentAxisNodes($context),
            'self' => DomRegistry::has($context) ? [$context] : [],
            'ancestor' => self::ancestorAxisNodes($context, false),
            'ancestor-or-self' => self::ancestorAxisNodes($context, true),
            'descendant' => self::descendantAxisNodes($context, false),
            'descendant-or-self' => self::descendantAxisNodes($context, true),
            'following-sibling' => self::siblingAxisNodes($context, true),
            'preceding-sibling' => self::siblingAxisNodes($context, false),
            'following' => self::followingAxisNodes($context),
            'preceding' => self::precedingAxisNodes($context),
            'attribute' => self::attributeAxisNodes($context),
            default => [],
        };
    }

    /** @return list<ObjectEntry> */
    private static function childAxisNodes(ObjectEntry $parent): array
    {
        $nodes = [];
        if (!DomRegistry::has($parent)) {
            return $nodes;
        }
        foreach (DomRegistry::state($parent)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /** @return list<ObjectEntry> */
    private static function parentAxisNodes(ObjectEntry $node): array
    {
        $parent = self::parentEntry($node);

        return null === $parent ? [] : [$parent];
    }

    private static function parentEntry(ObjectEntry $node): ?ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $parentId = DomRegistry::state($node)->parentId;
        if (null === $parentId) {
            return null;
        }

        return DomRegistry::entry($parentId);
    }

    /**
     * Ancestors in document order (root first). `$includeSelf` appends context (#31773).
     *
     * @return list<ObjectEntry>
     */
    private static function ancestorAxisNodes(ObjectEntry $node, bool $includeSelf): array
    {
        $chain = [];
        $current = self::parentEntry($node);
        while (null !== $current) {
            $chain[] = $current;
            $current = self::parentEntry($current);
        }
        $chain = array_reverse($chain);
        if ($includeSelf && DomRegistry::has($node)) {
            $chain[] = $node;
        }

        return $chain;
    }

    /**
     * @return list<ObjectEntry>
     */
    private static function descendantAxisNodes(ObjectEntry $context, bool $includeSelf): array
    {
        $nodes = [];
        if ($includeSelf && DomRegistry::has($context)) {
            $nodes[] = $context;
        }
        self::collectDescendantEntries($context, $nodes);

        return $nodes;
    }

    /** @param list<ObjectEntry> $nodes */
    private static function collectDescendantEntries(ObjectEntry $node, array &$nodes): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $nodes[] = $child;
            if (VmDom::isElement($child)
                || VmDom::isDocument($child)
                || VmDom::isDocumentFragment($child)
            ) {
                self::collectDescendantEntries($child, $nodes);
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function descendantOrSelfNodeIds(ObjectEntry $context): array
    {
        $ids = [];
        foreach (self::descendantAxisNodes($context, true) as $node) {
            $ids[] = $node->id;
        }

        return $ids;
    }

    /**
     * `$following` true → later siblings; false → earlier siblings in document order.
     *
     * @return list<ObjectEntry>
     */
    private static function siblingAxisNodes(ObjectEntry $node, bool $following): array
    {
        $parent = self::parentEntry($node);
        if (null === $parent || !DomRegistry::has($parent)) {
            return [];
        }
        $childIds = DomRegistry::state($parent)->childIds;
        $index = array_search($node->id, $childIds, true);
        if (false === $index) {
            return [];
        }
        $slice = $following
            ? array_slice($childIds, (int) $index + 1)
            : array_slice($childIds, 0, (int) $index);
        $nodes = [];
        foreach ($slice as $sibId) {
            $sib = DomRegistry::entry($sibId);
            if (null !== $sib) {
                $nodes[] = $sib;
            }
        }

        return $nodes;
    }

    /** @return list<ObjectEntry> */
    private static function followingAxisNodes(ObjectEntry $context): array
    {
        $document = self::ownerDocumentOrSelf($context);
        if (null === $document) {
            return [];
        }
        $order = self::documentOrderIds($document);
        $idx = array_search($context->id, $order, true);
        if (false === $idx) {
            return [];
        }
        $descendants = [];
        foreach (self::descendantAxisNodes($context, false) as $node) {
            $descendants[$node->id] = true;
        }
        $nodes = [];
        for ($i = (int) $idx + 1, $n = \count($order); $i < $n; ++$i) {
            $id = $order[$i];
            if (isset($descendants[$id])) {
                continue;
            }
            $entry = DomRegistry::entry($id);
            if (null !== $entry) {
                $nodes[] = $entry;
            }
        }

        return $nodes;
    }

    /** @return list<ObjectEntry> */
    private static function precedingAxisNodes(ObjectEntry $context): array
    {
        $document = self::ownerDocumentOrSelf($context);
        if (null === $document) {
            return [];
        }
        $order = self::documentOrderIds($document);
        $idx = array_search($context->id, $order, true);
        if (false === $idx) {
            return [];
        }
        $ancestors = [];
        foreach (self::ancestorAxisNodes($context, false) as $node) {
            $ancestors[$node->id] = true;
        }
        $nodes = [];
        for ($i = 0; $i < (int) $idx; ++$i) {
            $id = $order[$i];
            if (isset($ancestors[$id]) || $id === $context->id) {
                continue;
            }
            $entry = DomRegistry::entry($id);
            if (null !== $entry) {
                $nodes[] = $entry;
            }
        }

        return $nodes;
    }

    /**
     * Preorder ids from the document node (XPath document order without attrs/namespaces).
     *
     * @return list<int>
     */
    private static function documentOrderIds(ObjectEntry $document): array
    {
        $ids = [];
        if (DomRegistry::has($document)) {
            $ids[] = $document->id;
        }
        self::appendDocumentOrderChildIds($document, $ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private static function appendDocumentOrderChildIds(ObjectEntry $node, array &$ids): void
    {
        if (!DomRegistry::has($node)) {
            return;
        }
        foreach (DomRegistry::state($node)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            $ids[] = $child->id;
            if (VmDom::isElement($child)
                || VmDom::isDocument($child)
                || VmDom::isDocumentFragment($child)
            ) {
                self::appendDocumentOrderChildIds($child, $ids);
            }
        }
    }

    /** @return list<ObjectEntry> */
    private static function attributeAxisNodes(ObjectEntry $context): array
    {
        if (!VmDom::isElement($context) || !DomRegistry::has($context)) {
            return [];
        }
        $nodes = [];
        foreach (DomRegistry::state($context)->attributeNodeIds as $attrId) {
            $attr = DomRegistry::entry($attrId);
            if (null !== $attr) {
                $nodes[] = $attr;
            }
        }

        return $nodes;
    }

    /**
     * Child axis: nodes matching a path segment under $parent.
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectMatchingChildren(
        ObjectEntry $parent,
        string $segment,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $parsed = self::parsePathSegment($segment);
        $ids = [];
        if (!DomRegistry::has($parent)) {
            return $ids;
        }
        foreach (DomRegistry::state($parent)->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null === $child) {
                continue;
            }
            if (self::nodeMatchesPathTest($child, $parsed['test'], $namespaces)) {
                $ids[] = $child->id;
            }
        }

        return self::applyPathSegmentPredicates(
            $ids,
            $parsed['attr'],
            $parsed['attrValue'],
            $parsed['positionPred'],
            $namespaces,
            $parsed['fnPred'],
            $parsed['fnPredValue'],
            $parsed['attrNumeric'],
            $parsed['attrExists'] ?? false,
            $parsed['attrOp'] ?? null,
            $parsed['generalPred'] ?? null,
            $ctx,
            $xpath
        );
    }

    /**
     * Descendant axis for the first `//` segment (excludes context element self; #20377/#20456).
     * Absolute `//` callers pass the document so the document element is included (#21125).
     *
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function collectMatchingDescendants(
        ObjectEntry $context,
        string $segment,
        array $namespaces,
        ?Context $ctx = null,
        ?ObjectEntry $xpath = null
    ): array {
        $parsed = self::parsePathSegment($segment);
        $test = $parsed['test'];
        // Fast path: element name / * via existing collectors.
        if (!self::isNodeTypeTestName($test)) {
            $ids = self::collectDescendantElements($context, $test, $namespaces);

            return self::applyPathSegmentPredicates(
                $ids,
                $parsed['attr'],
                $parsed['attrValue'],
                $parsed['positionPred'],
                $namespaces,
                $parsed['fnPred'],
                $parsed['fnPredValue'],
                $parsed['attrNumeric'],
                $parsed['attrExists'] ?? false,
                $parsed['attrOp'] ?? null,
                $parsed['generalPred'] ?? null,
                $ctx,
                $xpath
            );
        }
        $ids = [];
        self::collectMatchingDescendantsRecursive($context, $test, $namespaces, $ids, false);

        return self::applyPathSegmentPredicates(
            $ids,
            $parsed['attr'],
            $parsed['attrValue'],
            $parsed['positionPred'],
            $namespaces,
            $parsed['fnPred'],
            $parsed['fnPredValue'],
            $parsed['attrNumeric'],
            $parsed['attrExists'] ?? false,
            $parsed['attrOp'] ?? null,
            $parsed['generalPred'] ?? null,
            $ctx,
            $xpath
        );
    }

    /**
     * @param list<int>             $ids
     * @param array<string, string> $namespaces
     */
    private static function collectMatchingDescendantsRecursive(
        ObjectEntry $node,
        string $test,
        array $namespaces,
        array &$ids,
        bool $includeSelf
    ): void {
        if ($includeSelf && self::nodeMatchesPathTest($node, $test, $namespaces)) {
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
            if (self::nodeMatchesPathTest($child, $test, $namespaces)) {
                $ids[] = $child->id;
            }
            // Descend into elements (and document / fragment roots).
            if (VmDom::isElement($child)
                || VmDom::isDocument($child)
                || VmDom::isDocumentFragment($child)
            ) {
                self::collectMatchingDescendantsRecursive($child, $test, $namespaces, $ids, false);
            }
        }
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

    /**
     * Attribute-axis node test (XPath 1.0 principal node type = attribute; #32003).
     *
     * `@*` / `attribute::*` / `attribute::node()` match every Attr on the axis.
     * Unprefixed name tests match null-namespace local names only; prefixed tests
     * use the registered namespace URI. `xmlns` declarations live on the namespace
     * axis, not here (libxml / php-src xpath.c).
     *
     * @param array<string, string> $namespaces
     */
    private static function attributeMatchesPathTest(
        ObjectEntry $attr,
        string $test,
        array $namespaces
    ): bool {
        if ('node()' === $test || '*' === $test) {
            return true;
        }
        if (self::isNodeTypeTestName($test)) {
            return false;
        }
        [$localName, $namespaceUri] = self::resolveQName($test, $namespaces);

        return self::attributeMatchesName($attr, $localName, $namespaceUri);
    }

    private static function attributeMatchesName(
        ObjectEntry $attr,
        string $localName,
        ?string $namespaceUri
    ): bool {
        $name = VmDom::readLocalName($attr);
        $nameMatch = '*' === $localName || $name === $localName;
        if (!$nameMatch) {
            return false;
        }
        $ns = VmDom::readNamespaceUri($attr) ?? '';
        if (null !== $namespaceUri) {
            return '*' === $namespaceUri || $ns === $namespaceUri;
        }
        if ('*' === $localName) {
            return true;
        }

        return '' === $ns;
    }

    private static function elementMatchesTag(
        ?ObjectEntry $element,
        string $localName,
        ?string $namespaceUri
    ): bool {
        if (null === $element || !VmDom::isElement($element)) {
            return false;
        }
        $name = VmDom::readLocalName($element);
        $nameMatch = '*' === $localName || $name === $localName;
        if (!$nameMatch) {
            return false;
        }
        $ns = VmDom::readNamespaceUri($element) ?? '';
        // Prefixed QName — match expanded namespace URI.
        if (null !== $namespaceUri) {
            return '*' === $namespaceUri || $ns === $namespaceUri;
        }
        // Unprefixed `*` — any namespace (XPath principal node type wildcard).
        if ('*' === $localName) {
            return true;
        }
        // Unprefixed name test — null namespace URI only (XPath 1.0; #21125 / #26007).
        // Dom\HTMLDocument elements default to the XHTML namespace, so //div does not
        // match unless the caller registers that NS (or parses with HTML_NO_DEFAULT_NS).
        // getElementsByTagName remains HTML-aware separately — this is not that API.
        return '' === $ns;
    }

    /**
     * [@attr] existence predicate — true when the element carries the named attribute (#30439).
     *
     * @param array<string, string> $namespaces
     */
    private static function elementHasAttribute(
        ?ObjectEntry $element,
        string $attrName,
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

            return VmDom::hasAttributeNS($element, $namespace, $local);
        }

        return VmDom::hasAttribute($element, $attrName);
    }

    /**
     * [@attr op N] numeric comparison predicate — XPath 1.0 >, >=, <, <=, != (#30439).
     *
     * @param array<string, string> $namespaces
     */
    private static function elementAttributeCompare(
        ?ObjectEntry $element,
        string $attrName,
        string $op,
        string $rhs,
        array $namespaces
    ): bool {
        if (null === $element || !VmDom::isElement($element)) {
            return false;
        }
        if (!self::elementHasAttribute($element, $attrName, $namespaces)) {
            return false;
        }
        if (str_contains($attrName, ':')) {
            [$prefix, $local] = explode(':', $attrName, 2);
            $namespace = $namespaces[$prefix] ?? null;
            if (null === $namespace) {
                return false;
            }
            $actual = VmDom::getAttributeNS($element, $namespace, $local);
        } else {
            $actual = VmDom::getAttributeNS($element, null, $attrName);
        }

        return self::compareNumbers(self::numberize($actual), $op, self::numberize($rhs));
    }

    /**
     * Attribute predicate match. Quoted RHS is string equality; unquoted numeric RHS
     * uses XPath 1.0 number conversion (php-src/libxml; #24333).
     *
     * @param array<string, string> $namespaces
     */
    private static function elementAttributeEquals(
        ?ObjectEntry $element,
        string $attrName,
        string $attrValue,
        array $namespaces,
        bool $numericCompare = false
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
            $actual = VmDom::getAttributeNS($element, $namespace, $local);
        } else {
            $actual = VmDom::getAttributeNS($element, null, $attrName);
        }
        if ($numericCompare) {
            return self::compareNumbers(self::numberize($actual), '=', self::numberize($attrValue));
        }

        return $actual === $attrValue;
    }

    private static function isBooleanExpression(string $expression): bool
    {
        if (preg_match('~^(true|false|boolean\(|not\(|starts-with\(|contains\(|lang\()~i', $expression)) {
            return true;
        }
        // XPath 1.0 `or` / `and` — boolean result, not a node-set union (`|`) (#32050).
        if (null !== self::findTopLevelWordOperator($expression, 'or')
            || null !== self::findTopLevelWordOperator($expression, 'and')
        ) {
            return true;
        }

        // XPath 1.0 comparisons (= != < <= > >=) at top level (#20280).
        return null !== self::findTopLevelComparison($expression);
    }

    private static function isNumericExpression(string $expression): bool
    {
        if (preg_match('~^(number|count|sum|string-length|floor|ceiling|round)\(~i', $expression)) {
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
        if (null !== self::tryParseStringLiteral($expression)) {
            return true;
        }

        // XPath 1.0 string core + name()/string() (#20818; follows #20280).
        return (bool) preg_match(
            '~^(string|name|concat|substring-before|substring-after|substring|normalize-space|translate|local-name|namespace-uri)\(~i',
            $expression
        );
    }

    private static function isNumericLiteral(string $expression): bool
    {
        return 1 === preg_match('~^[+-]?(?:\d+\.?\d*|\.\d+)$~', $expression);
    }

    private static function evaluateBoolean(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): bool {
        if (0 === strcasecmp($expression, 'true()')) {
            return true;
        }
        if (0 === strcasecmp($expression, 'false()')) {
            return false;
        }
        // `or` then `and` (XPath 1.0 §3.4 precedence; #32050). Word tokens only —
        // `ancestor-or-self` / `standard` must not split.
        $or = self::findTopLevelWordOperator($expression, 'or');
        if (null !== $or) {
            return self::booleanize(self::evaluateToMixed($ctx, $xpath, $or['left'], $contextNode, $registerNodeNS))
                || self::booleanize(self::evaluateToMixed($ctx, $xpath, $or['right'], $contextNode, $registerNodeNS));
        }
        $and = self::findTopLevelWordOperator($expression, 'and');
        if (null !== $and) {
            return self::booleanize(self::evaluateToMixed($ctx, $xpath, $and['left'], $contextNode, $registerNodeNS))
                && self::booleanize(self::evaluateToMixed($ctx, $xpath, $and['right'], $contextNode, $registerNodeNS));
        }
        if (preg_match('~^not\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'not');
            if (null === $inner) {
                throw new \DOMException('Invalid expression');
            }

            return !self::booleanize(self::evaluateToMixed($ctx, $xpath, $inner, $contextNode, $registerNodeNS));
        }
        // starts-with(string, string) / contains(string, string) — XPath 1.0 (#20818).
        if (preg_match('~^starts-with\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'starts-with');
            if (null === $args || 2 !== \count($args)) {
                throw new \DOMException('Invalid expression');
            }
            $haystack = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $needle = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));

            return str_starts_with($haystack, $needle);
        }
        if (preg_match('~^contains\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'contains');
            if (null === $args || 2 !== \count($args)) {
                throw new \DOMException('Invalid expression');
            }
            $haystack = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $needle = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));

            return str_contains($haystack, $needle);
        }
        // lang(string) — ancestor xml:lang match (XPath 1.0 / libxml; #23323).
        if (preg_match('~^lang\(~i', $expression)) {
            return self::evaluateLangFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        }
        $comparison = self::findTopLevelComparison($expression);
        if (null !== $comparison) {
            return self::evaluateComparison(
                $ctx,
                $xpath,
                $comparison['left'],
                $comparison['op'],
                $comparison['right'],
                $contextNode,
                $registerNodeNS
            );
        }
        if (preg_match('~^boolean\((.+)\)$~i', $expression, $matches)) {
            $inner = trim($matches[1]);
            if (preg_match('~^count\((.+)\)$~i', $inner)) {
                return 0.0 !== self::evaluateNumber($ctx, $xpath, $inner, $contextNode, $registerNodeNS);
            }
            if (preg_match('~^string\((.+)\)$~i', $inner)) {
                return '' !== self::evaluateString($ctx, $xpath, $inner, $contextNode, $registerNodeNS);
            }
            if (preg_match('~^(number|sum)\((.+)\)$~i', $inner)) {
                $number = self::evaluateNumber($ctx, $xpath, $inner, $contextNode, $registerNodeNS);

                return 0.0 !== $number && !is_nan($number);
            }
            try {
                $nodeIds = self::evaluateNodeSet($ctx, $xpath, $inner, $contextNode, $registerNodeNS);

                return [] !== $nodeIds;
            } catch (\DOMException) {
                // Fall through to string-value coercion for unsupported inner shapes.
            }
            $value = self::evaluateScalar($ctx, $xpath, $inner, $contextNode, $registerNodeNS);

            return self::booleanize($value);
        }

        throw new \DOMException('Invalid expression');
    }

    private static function evaluateNumber(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): float {
        $expression = trim($expression);
        if (self::isNumericLiteral($expression)) {
            return (float) $expression;
        }
        if (preg_match('~^count\((.+)\)$~i', $expression, $matches)) {
            return (float) \count(self::evaluateNodeSet($ctx, $xpath, trim($matches[1]), $contextNode, $registerNodeNS));
        }
        if (preg_match('~^number\((.+)\)$~i', $expression, $matches)) {
            $value = self::evaluateToMixed($ctx, $xpath, trim($matches[1]), $contextNode, $registerNodeNS);

            return self::numberize($value);
        }
        // string-length([string]) — XPath 1.0 character count (#20818).
        if (preg_match('~^string-length\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'string-length');
            if (null === $args || \count($args) > 1) {
                throw new \DOMException('Invalid expression');
            }
            if ([] === $args || '' === trim($args[0])) {
                $str = self::stringify(self::contextNodeStringValue($xpath, $contextNode));
            } else {
                $str = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            }

            return (float) self::xpathStringLength($str);
        }
        // floor / ceiling / round — XPath 1.0 number core (#20818).
        if (preg_match('~^floor\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'floor');
            if (null === $inner || '' === $inner) {
                throw new \DOMException('Invalid expression');
            }
            $n = self::numberize(self::evaluateToMixed($ctx, $xpath, $inner, $contextNode, $registerNodeNS));

            return is_nan($n) || is_infinite($n) ? $n : floor($n);
        }
        if (preg_match('~^ceiling\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'ceiling');
            if (null === $inner || '' === $inner) {
                throw new \DOMException('Invalid expression');
            }
            $n = self::numberize(self::evaluateToMixed($ctx, $xpath, $inner, $contextNode, $registerNodeNS));

            return is_nan($n) || is_infinite($n) ? $n : ceil($n);
        }
        if (preg_match('~^round\(~i', $expression)) {
            $inner = self::wrappedFunctionInner($expression, 'round');
            if (null === $inner || '' === $inner) {
                throw new \DOMException('Invalid expression');
            }
            $n = self::numberize(self::evaluateToMixed($ctx, $xpath, $inner, $contextNode, $registerNodeNS));
            if (is_nan($n) || is_infinite($n)) {
                return $n;
            }

            // XPath 1.0: round half toward +∞ ≡ floor(n + 0.5).
            return floor($n + 0.5);
        }
        // XPath 1.0 sum(node-set): coerce each string-value to number (#19682).
        if (preg_match('~^sum\((.+)\)$~i', $expression, $matches)) {
            $nodeIds = self::evaluateNodeSet($ctx, $xpath, trim($matches[1]), $contextNode, $registerNodeNS);
            $sum = 0.0;
            foreach ($nodeIds as $nodeId) {
                $node = DomRegistry::entry($nodeId);
                if (null === $node) {
                    continue;
                }
                $value = '';
                if (VmDom::isElement($node) || VmDom::isTextNode($node) || VmDom::isAttr($node)) {
                    $value = self::xpathStringValue($node);
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
            $left = self::evaluateNumber($ctx, $xpath, $additive['left'], $contextNode, $registerNodeNS);
            $right = self::evaluateNumber($ctx, $xpath, $additive['right'], $contextNode, $registerNodeNS);
            if ('+' === $additive['op']) {
                return $left + $right;
            }

            return $left - $right;
        }
        $multiplicative = self::findTopLevelMultiplicative($expression);
        if (null !== $multiplicative) {
            $left = self::evaluateNumber($ctx, $xpath, $multiplicative['left'], $contextNode, $registerNodeNS);
            $right = self::evaluateNumber($ctx, $xpath, $multiplicative['right'], $contextNode, $registerNodeNS);
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
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): string {
        $literal = self::tryParseStringLiteral($expression);
        if (null !== $literal) {
            return $literal;
        }
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
            $nodeIds = self::evaluateNodeSet($ctx, $xpath, $inner, $contextNode, $registerNodeNS);
            if ([] === $nodeIds) {
                return '';
            }
            $node = DomRegistry::entry($nodeIds[0]);
            if (null === $node || !DomRegistry::has($node)) {
                return '';
            }

            return DomRegistry::state($node)->nodeName ?? '';
        }
        // local-name([node-set]) — XPath 1.0 (#20818).
        if (preg_match('~^local-name\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'local-name');
            if (null === $args || \count($args) > 1) {
                throw new \DOMException('Invalid expression');
            }
            $node = self::firstNodeFromOptionalArg($ctx, $xpath, $args, $contextNode, $registerNodeNS);
            if (null === $node) {
                return '';
            }
            $name = DomRegistry::state($node)->nodeName ?? '';
            $colon = strrpos($name, ':');

            return false === $colon ? $name : substr($name, $colon + 1);
        }
        // namespace-uri([node-set]) — XPath 1.0 (#20818, #21238).
        if (preg_match('~^namespace-uri\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'namespace-uri');
            if (null === $args || \count($args) > 1) {
                throw new \DOMException('Invalid expression');
            }
            $node = self::firstNodeFromOptionalArg($ctx, $xpath, $args, $contextNode, $registerNodeNS);
            if (null === $node) {
                return '';
            }

            // DomNodeState stores namespaceUri; match predicate path (#21125).
            return VmDom::readNamespaceUri($node) ?? '';
        }
        // concat(string, string, ...) — XPath 1.0 (#20818).
        if (preg_match('~^concat\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'concat');
            if (null === $args || \count($args) < 2) {
                throw new \DOMException('Invalid expression');
            }
            $out = '';
            foreach ($args as $arg) {
                $out .= self::stringify(self::evaluateToMixed($ctx, $xpath, trim($arg), $contextNode, $registerNodeNS));
            }

            return $out;
        }
        // substring-before / substring-after (#20818).
        if (preg_match('~^substring-before\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'substring-before');
            if (null === $args || 2 !== \count($args)) {
                throw new \DOMException('Invalid expression');
            }
            $haystack = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $needle = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));
            if ('' === $needle) {
                return '';
            }
            $pos = strpos($haystack, $needle);

            return false === $pos ? '' : substr($haystack, 0, $pos);
        }
        if (preg_match('~^substring-after\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'substring-after');
            if (null === $args || 2 !== \count($args)) {
                throw new \DOMException('Invalid expression');
            }
            $haystack = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $needle = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));
            if ('' === $needle) {
                return $haystack;
            }
            $pos = strpos($haystack, $needle);

            return false === $pos ? '' : substr($haystack, $pos + \strlen($needle));
        }
        // substring(string, number[, number]) — 1-based, XPath round on indices (#20818).
        if (preg_match('~^substring\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'substring');
            if (null === $args || \count($args) < 2 || \count($args) > 3) {
                throw new \DOMException('Invalid expression');
            }
            $str = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $start = self::numberize(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));
            $len = null;
            if (isset($args[2])) {
                $len = self::numberize(self::evaluateToMixed($ctx, $xpath, trim($args[2]), $contextNode, $registerNodeNS));
            }

            return self::xpathSubstring($str, $start, $len);
        }
        // normalize-space([string]) (#20818).
        if (preg_match('~^normalize-space\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'normalize-space');
            if (null === $args || \count($args) > 1) {
                throw new \DOMException('Invalid expression');
            }
            if ([] === $args || '' === trim($args[0])) {
                $str = self::stringify(self::contextNodeStringValue($xpath, $contextNode));
            } else {
                $str = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            }

            return self::xpathNormalizeSpace($str);
        }
        // translate(string, string, string) (#20818).
        if (preg_match('~^translate\(~i', $expression)) {
            $args = self::wrappedFunctionArgs($expression, 'translate');
            if (null === $args || 3 !== \count($args)) {
                throw new \DOMException('Invalid expression');
            }
            $str = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS));
            $from = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[1]), $contextNode, $registerNodeNS));
            $to = self::stringify(self::evaluateToMixed($ctx, $xpath, trim($args[2]), $contextNode, $registerNodeNS));

            return self::xpathTranslate($str, $from, $to);
        }
        if (!preg_match('~^string\(~i', $expression)) {
            throw new \DOMException('Invalid expression');
        }
        $inner = self::wrappedFunctionInner($expression, 'string');
        if (null === $inner) {
            throw new \DOMException('Invalid expression');
        }
        if ('' === $inner) {
            return self::stringify(self::contextNodeStringValue($xpath, $contextNode));
        }
        $value = self::evaluateToMixed($ctx, $xpath, trim($inner), $contextNode, $registerNodeNS);

        return self::stringify($value);
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
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): mixed {
        $expression = trim($expression);
        if ('' === $expression) {
            throw new \DOMException('Invalid expression');
        }
        // Nested php:function() / php:functionString() inside string()/number()/… (#22719).
        $phpFn = self::tryEvaluatePhpFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if (null !== $phpFn) {
            return self::phpFunctionResultToMixed($phpFn);
        }
        $nsFn = self::tryEvaluateNamespacedPhpFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if (null !== $nsFn) {
            return self::phpFunctionResultToMixed($nsFn);
        }
        if (self::isBooleanExpression($expression)) {
            return self::evaluateBoolean($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        }
        if (self::isNumericExpression($expression)) {
            return self::evaluateNumber($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        }
        if (self::isStringExpression($expression)) {
            return self::evaluateString($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        }

        return self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * Convert php:function* Variable result to evaluateToMixed scalar/node-set shape (#22719).
     */
    private static function phpFunctionResultToMixed(Variable $result): mixed
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_NULL === $result->type) {
            return null;
        }
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }
        if (Variable::TYPE_STRING === $result->type) {
            return $result->toString();
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            return $result->toInt();
        }
        if (Variable::TYPE_FLOAT === $result->type) {
            return $result->toFloat();
        }

        return $result->toString();
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
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): bool {
        $left = self::evaluateToMixed($ctx, $xpath, $leftExpr, $contextNode, $registerNodeNS);
        $right = self::evaluateToMixed($ctx, $xpath, $rightExpr, $contextNode, $registerNodeNS);

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
                $leftStr = self::xpathStringValue($leftNode);
                foreach ($rightNodes as $rightId) {
                    $rightNode = DomRegistry::entry($rightId);
                    $rightStr = self::xpathStringValue($rightNode);
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
                $leftStr = self::xpathStringValue($leftNode);
                if (self::compareScalarsAsXPath($leftStr, $op, $right)) {
                    return true;
                }
            }

            return false;
        }
        foreach ($rightNodes ?? [] as $rightId) {
            $rightNode = DomRegistry::entry($rightId);
            $rightStr = self::xpathStringValue($rightNode);
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
            $str = self::xpathStringValue($node);

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

    /** XPath 1.0 string() conversion for evaluate() helpers (#20818). */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
            // Match php/libxml: drop trailing .0 for whole numbers.
            if (floor($value) == $value) {
                return (string) (int) $value;
            }

            return (string) $value;
        }
        if (is_array($value)) {
            if ([] === $value) {
                return '';
            }
            $node = DomRegistry::entry($value[0]);

            return self::xpathStringValue($node);
        }

        return '';
    }

    private static function tryParseStringLiteral(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('~^"(.*)"$~s', $expression, $m) || preg_match("~^'(.*)'$~s", $expression, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Args of func(...) when the call spans the whole expression.
     *
     * @return list<string>|null
     */
    private static function wrappedFunctionArgs(string $expression, string $funcName): ?array
    {
        $inner = self::wrappedFunctionInner($expression, $funcName);
        if (null === $inner) {
            return null;
        }

        return self::splitXPathCallArgs($inner);
    }

    private static function contextNodeStringValue(ObjectEntry $xpath, ?ObjectEntry $contextNode): mixed
    {
        $node = $contextNode;
        if (null === $node) {
            $state = DomRegistry::state($xpath);
            $node = DomRegistry::entry($state->xpathDocumentId ?? 0);
        }
        if (null === $node || !DomRegistry::has($node)) {
            return '';
        }
        if (VmDom::isDocument($node)) {
            $rootVar = $node->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $rootVar->type) {
                return '';
            }
            $root = $rootVar->toObject();

            return self::xpathStringValue($root);
        }
        if (VmDom::isElement($node) || VmDom::isTextNode($node) || VmDom::isAttr($node)) {
            return self::xpathStringValue($node);
        }

        return '';
    }

    /**
     * @param list<string> $args
     */
    private static function firstNodeFromOptionalArg(
        Context $ctx,
        ObjectEntry $xpath,
        array $args,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): ?ObjectEntry {
        if ([] === $args || '' === trim($args[0])) {
            $node = $contextNode;
            if (null === $node) {
                $state = DomRegistry::state($xpath);
                $node = DomRegistry::entry($state->xpathDocumentId ?? 0);
            }

            return (null !== $node && DomRegistry::has($node)) ? $node : null;
        }
        $nodeIds = self::evaluateNodeSet($ctx, $xpath, trim($args[0]), $contextNode, $registerNodeNS);
        if ([] === $nodeIds) {
            return null;
        }

        return DomRegistry::entry($nodeIds[0]);
    }

    private static function xpathStringLength(string $str): int
    {
        if (\function_exists('mb_strlen')) {
            return (int) mb_strlen($str, 'UTF-8');
        }

        return \strlen($str);
    }

    /**
     * XPath 1.0 substring(): 1-based start; start/len rounded via floor(n+0.5).
     */
    private static function xpathSubstring(string $str, float $start, ?float $len): string
    {
        if (is_nan($start) || (null !== $len && is_nan($len))) {
            return '';
        }
        $chars = self::xpathChars($str);
        $count = \count($chars);
        $startPos = (int) floor($start + 0.5);
        if (null === $len) {
            if ($startPos > $count) {
                return '';
            }
            $from = max(1, $startPos) - 1;

            return implode('', \array_slice($chars, $from));
        }
        $length = (int) floor($len + 0.5);
        if ($length <= 0) {
            return '';
        }
        // Characters with position p where start <= p < start+len.
        $from = max(1, $startPos);
        $toExclusive = $startPos + $length;
        if ($from > $count || $from >= $toExclusive) {
            return '';
        }
        $sliceStart = $from - 1;
        $sliceLen = min($count, $toExclusive - 1) - $sliceStart;

        return $sliceLen <= 0 ? '' : implode('', \array_slice($chars, $sliceStart, $sliceLen));
    }

    /** @return list<string> */
    private static function xpathChars(string $str): array
    {
        if ('' === $str) {
            return [];
        }
        if (\function_exists('mb_str_split')) {
            return mb_str_split($str, 1, 'UTF-8');
        }
        if (\function_exists('preg_split')) {
            $parts = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);

            return false === $parts ? str_split($str) : $parts;
        }

        return str_split($str);
    }

    private static function xpathNormalizeSpace(string $str): string
    {
        $str = preg_replace('/[ \t\r\n]+/u', ' ', $str) ?? $str;

        return trim($str);
    }

    private static function xpathTranslate(string $str, string $from, string $to): string
    {
        $fromChars = self::xpathChars($from);
        $toChars = self::xpathChars($to);
        $map = [];
        $delete = [];
        foreach ($fromChars as $i => $ch) {
            if (isset($map[$ch]) || isset($delete[$ch])) {
                continue; // first occurrence wins (XPath 1.0)
            }
            if ($i < \count($toChars)) {
                $map[$ch] = $toChars[$i];
            } else {
                $delete[$ch] = true;
            }
        }
        $out = '';
        foreach (self::xpathChars($str) as $ch) {
            if (isset($delete[$ch])) {
                continue;
            }
            $out .= $map[$ch] ?? $ch;
        }

        return $out;
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
            // Skip ops inside () calls and [] predicates (#21148 — [@id="…"] is not a comparison).
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
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
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
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
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
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

    /**
     * Leftmost top-level XPath 1.0 word operator (`or` / `and`; also reused shape of `div`/`mod`).
     * Skips quotes, parens, predicates, and NCName fragments (`ancestor-or-self`) (#32050).
     *
     * @return array{op: string, left: string, right: string}|null
     */
    private static function findTopLevelWordOperator(string $expression, string $word): ?array
    {
        $depth = 0;
        $quote = null;
        $len = \strlen($expression);
        $wordLen = \strlen($word);
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
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
                --$depth;
                continue;
            }
            if (0 !== $depth) {
                continue;
            }
            if ($i + $wordLen > $len || 0 !== substr_compare($expression, $word, $i, $wordLen)) {
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
                return ['op' => $word, 'left' => $left, 'right' => $right];
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
        ?ObjectEntry $contextNode,
        bool $registerNodeNS = true
    ): mixed {
        $nodeIds = self::evaluateNodeSet($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if ([] === $nodeIds) {
            return '';
        }
        $node = DomRegistry::entry($nodeIds[0]);
        if (null === $node) {
            return '';
        }
        // Attr string-value is the attribute value (XPath 1.0 / php-src xpath.c; #19352).
        // Element string-value is descendant text — not Dom\Element::$nodeValue (#21271).
        if (VmDom::isElement($node) || VmDom::isTextNode($node) || VmDom::isAttr($node)) {
            return self::xpathStringValue($node);
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
        bool $registerNodeNS,
        ?Frame $frame = null
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
        $callNameRaw = $prefixMatch[2];
        $callName = strtolower($callNameRaw);
        $nsUri = $state->xpathNamespaces[$prefix] ?? null;
        if (null === $nsUri) {
            // php-src/libxml: xmlXPathCompOpEval unbound prefix → E_WARNING + false for the
            // whole evaluate() (including string(php:function(...)) wrappers) (#23534).
            throw new \DOMException(sprintf(
                'xmlXPathCompOpEval: function %s bound to undefined prefix %s',
                $callNameRaw,
                $prefix
            ));
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

        return self::coercePhpFunctionReturn($ctx, $xpath, $result, $frame);
    }

    /**
     * Top-level prefix:localName(...) for registerPhpFunctionNS() (#20119).
     */
    private static function tryEvaluateNamespacedPhpFunction(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode,
        bool $registerNodeNS,
        ?Frame $frame = null
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
            $contextNode,
            $frame
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

        // Absolute `//tag[…]` is document-scoped (libxml; #21125).
        $document = self::ownerDocumentOrSelf($context) ?? $context;
        $nodeIds = self::collectDescendantElements($document, $tag, $state->xpathNamespaces);
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
                $element,
                null
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
        ?ObjectEntry $contextNode,
        ?Frame $frame = null
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

        return self::coercePhpFunctionReturn(
            $ctx,
            $xpath,
            VmCallable::invoke($ctx, $callable, ...$callArgs),
            $frame
        );
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
            $var->bool(self::evaluateBoolean(
                $ctx,
                $xpath,
                $expression,
                $contextNode,
                DomRegistry::state($xpath)->xpathRegisterNodeNamespaces
            ));

            return $var;
        }
        if (self::isNumericExpression($expression)) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float(self::evaluateNumber(
                $ctx,
                $xpath,
                $expression,
                $contextNode,
                DomRegistry::state($xpath)->xpathRegisterNodeNamespaces
            ));

            return $var;
        }
        if (self::isStringExpression($expression)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string(self::evaluateString(
                $ctx,
                $xpath,
                $expression,
                $contextNode,
                DomRegistry::state($xpath)->xpathRegisterNodeNamespaces
            ));

            return $var;
        }
        // Node-set argument — php:function passes DOMNode[]; functionString coerces to string-value
        // of the first node (php-src xpath_callbacks.c; #19331 / #19709).
        $nodeIds = self::evaluateNodeSet(
            $ctx,
            $xpath,
            $expression,
            $contextNode,
            DomRegistry::state($xpath)->xpathRegisterNodeNamespaces
        );
        if ($nodesetToString) {
            $var = new Variable(Variable::TYPE_STRING);
            if ([] === $nodeIds) {
                $var->string('');

                return $var;
            }
            $node = DomRegistry::entry($nodeIds[0]);
            $var->string(self::xpathStringValue($node));

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

    /**
     * php-src ext/dom/xpath_callbacks.c — php_dom_xpath_callback_dispatch return push (#22797).
     * DOM node → nodeset; bool → bool; else convert_to_string (null/int/float/array/…).
     */
    private static function coercePhpFunctionReturn(
        Context $ctx,
        ObjectEntry $xpath,
        Variable $result,
        ?Frame $frame = null
    ): Variable {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result;
        }
        if (Variable::TYPE_OBJECT === $result->type) {
            $obj = $result->toObject();
            if (null !== $obj && VmDom::isDomNodeInstance($obj, $ctx)) {
                $nodeIds = [$obj->id];
                if (VmDom::prefersDomNodeList($xpath)) {
                    return VmDom::createDomNodeList($ctx, $nodeIds);
                }

                return VmDom::createNodeList($ctx, $nodeIds);
            }
            throw new \TypeError(
                'Only objects that are instances of DOM nodes can be converted to an XPath expression'
            );
        }
        if (Variable::TYPE_STRING === $result->type) {
            return $result;
        }
        // convert_to_string — null→'', int/float→decimal string, array→'Array'+E_WARNING (#22814/#22816).
        $out = new Variable(Variable::TYPE_STRING);
        if (Variable::TYPE_ARRAY === $result->type) {
            $ctx->errors->languageWarning(
                'Array to string conversion',
                null !== $frame && '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
                0,
                $ctx,
                $frame
            );
            $out->string('Array');

            return $out;
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
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
                $buf .= $ch;
                continue;
            }
            if (')' === $ch || ']' === $ch) {
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
