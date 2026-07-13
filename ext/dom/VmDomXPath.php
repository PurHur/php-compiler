<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

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

    public static function query(
        Context $ctx,
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode = null,
        bool $registerNodeNS = false
    ): Variable {
        $nodeIds = self::evaluateNodeSet($xpath, $expression, $contextNode, $registerNodeNS);

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
        if (self::isBooleanExpression($expression)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(self::evaluateBoolean($xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isNumericExpression($expression)) {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float(self::evaluateNumber($xpath, $expression, $contextNode));

            return $var;
        }
        if (self::isStringExpression($expression)) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string(self::evaluateString($xpath, $expression, $contextNode));

            return $var;
        }

        return self::query($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @return list<int>
     */
    private static function evaluateNodeSet(
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

        if (preg_match(
            '~^//([*\w][\w:-]*)(?:\[@([^\]=]+)=["\']([^"\']*)["\']\])?$~',
            $expression,
            $matches
        )) {
            $tag = $matches[1];
            $attr = $matches[2] ?? null;
            $attrValue = $matches[3] ?? null;
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

            return $nodeIds;
        }

        if (preg_match('~^/(.+)$~', $expression, $matches)) {
            return self::evaluateAbsolutePath($context, $matches[1], $state->xpathNamespaces);
        }

        if (preg_match('~^([*\w][\w:-]*)$~', $expression, $matches)) {
            return self::collectChildElements($context, $matches[1], $state->xpathNamespaces);
        }

        throw new \DOMException('Invalid expression');
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
     * @param array<string, string> $namespaces
     *
     * @return list<int>
     */
    private static function evaluateAbsolutePath(
        ObjectEntry $context,
        string $path,
        array $namespaces
    ): array {
        $segments = explode('/', $path);
        $current = VmDom::isDocument($context)
            ? $context->getProperty(VmDom::PROP_DOCUMENT_ELEMENT)->resolveIndirect()->toObject()
            : $context;
        $lastIndex = \count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ('' === $segment) {
                continue;
            }
            if (!VmDom::isElement($current)) {
                return [];
            }
            $children = self::collectChildElements($current, $segment, $namespaces);
            if ([] === $children) {
                return [];
            }
            if ($index === $lastIndex) {
                return $children;
            }
            $current = DomRegistry::entry($children[0]);
            if (null === $current) {
                return [];
            }
        }

        return [];
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
        return (bool) preg_match('~^(true|false|boolean\()~i', $expression);
    }

    private static function isNumericExpression(string $expression): bool
    {
        return (bool) preg_match('~^(number|count)\(~i', $expression);
    }

    private static function isStringExpression(string $expression): bool
    {
        return (bool) preg_match('~^string\(~i', $expression);
    }

    private static function evaluateBoolean(
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
        if (preg_match('~^boolean\((.+)\)$~i', $expression, $matches)) {
            $value = self::evaluateScalar($xpath, trim($matches[1]), $contextNode);

            return self::booleanize($value);
        }

        throw new \DOMException('Invalid expression');
    }

    private static function evaluateNumber(
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): float {
        if (preg_match('~^count\((.+)\)$~i', $expression, $matches)) {
            return (float) \count(self::evaluateNodeSet($xpath, trim($matches[1]), $contextNode, false));
        }
        if (preg_match('~^number\((.+)\)$~i', $expression, $matches)) {
            $value = self::evaluateScalar($xpath, trim($matches[1]), $contextNode);
            if (is_numeric($value)) {
                return (float) $value;
            }

            return NAN;
        }

        throw new \DOMException('Invalid expression');
    }

    private static function evaluateString(
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): string {
        if (!preg_match('~^string\((.+)\)$~i', $expression, $matches)) {
            throw new \DOMException('Invalid expression');
        }
        $value = self::evaluateScalar($xpath, trim($matches[1]), $contextNode);
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

    private static function evaluateScalar(
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): mixed {
        $nodeIds = self::evaluateNodeSet($xpath, $expression, $contextNode, false);
        if ([] === $nodeIds) {
            return '';
        }
        $node = DomRegistry::entry($nodeIds[0]);
        if (null === $node) {
            return '';
        }
        if (VmDom::isElement($node) || VmDom::isTextNode($node)) {
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
}
