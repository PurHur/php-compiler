<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
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
        $phpFn = self::tryEvaluatePhpFunction($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
        if (null !== $phpFn) {
            return $phpFn;
        }
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
            $inner = trim($matches[1]);
            if (preg_match('~^count\((.+)\)$~i', $inner)) {
                return 0.0 !== self::evaluateNumber($xpath, $inner, $contextNode);
            }
            if (preg_match('~^string\((.+)\)$~i', $inner)) {
                return '' !== self::evaluateString($xpath, $inner, $contextNode);
            }
            if (preg_match('~^number\((.+)\)$~i', $inner)) {
                $number = self::evaluateNumber($xpath, $inner, $contextNode);

                return 0.0 !== $number && !is_nan($number);
            }
            try {
                $nodeIds = self::evaluateNodeSet($xpath, $inner, $contextNode, false);

                return [] !== $nodeIds;
            } catch (\DOMException) {
                // Fall through to string-value coercion for unsupported inner shapes.
            }
            $value = self::evaluateScalar($xpath, $inner, $contextNode);

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
        $handlerName = self::resolvePhpFunctionHandlerName($xpath, trim($argExprs[0]), $contextNode);
        if (!self::assertPhpFunctionAllowed($state, $handlerName)) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);

            return $var;
        }
        $asString = 'functionstring' === $callName;
        $callArgs = [];
        for ($i = 1, $n = \count($argExprs); $i < $n; ++$i) {
            $callArgs[] = self::evaluatePhpFunctionArg($xpath, trim($argExprs[$i]), $contextNode, $asString);
        }
        $callback = new Variable(Variable::TYPE_STRING);
        $callback->string($handlerName);
        $result = VmCallable::invoke($ctx, $callback, ...$callArgs);

        return self::coercePhpFunctionReturn($result);
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
        ObjectEntry $xpath,
        string $expression,
        ?ObjectEntry $contextNode
    ): string {
        if (preg_match('~^"(.*)"$~s', $expression, $m) || preg_match("~^'(.*)'$~s", $expression, $m)) {
            return $m[1];
        }
        try {
            $value = self::evaluateScalar($xpath, $expression, $contextNode);
        } catch (\DOMException) {
            throw new \TypeError('Handler name must be a string');
        }
        if (!is_string($value)) {
            throw new \TypeError('Handler name must be a string');
        }

        return $value;
    }

    private static function evaluatePhpFunctionArg(
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
        // Node-set argument — php:function passes DOMNode arrays; functionString coerces to string.
        $nodeIds = self::evaluateNodeSet($xpath, $expression, $contextNode, false);
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
        // php:function() with string() already handled above; bare path → string via first node text.
        $var = new Variable(Variable::TYPE_STRING);
        if ([] === $nodeIds) {
            $var->string('');

            return $var;
        }
        $node = DomRegistry::entry($nodeIds[0]);
        $var->string(null !== $node ? (VmDom::readNodeValue($node) ?? '') : '');

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
