<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMXPath::query() (#18493, #27275). */
final class JitDomXPathQueryUserScript
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private static ?string $lastCacheKey = null;

    /** Last simple //tag query tag for NodeList::item(N) materialization (#27275). */
    private static ?string $lastQueryTag = null;

    /** Last host-folded axis/boolean XPath for NodeList::item(N>0) (#32003). */
    private static ?string $lastXPathAxisExpr = null;

    /** Per-compilation-unit axis id → XPath expr; stamped on each axis NodeList (#32003). */
    private static int $nextXPathAxisId = 0;

    /** @var array<int, string> */
    private static array $xpathAxisExprById = [];

    /** @var array<int, string> compile-time XML captured at each XPath query() (#36065). */
    private static array $xpathSnapshotXmlByAxisId = [];

    /** @return array<int, string> */
    public static function xpathSnapshotXmlByAxisId(): array
    {
        return self::$xpathSnapshotXmlByAxisId;
    }

    public static function snapshotXmlForAxisId(int $axisId): ?string
    {
        return self::$xpathSnapshotXmlByAxisId[$axisId] ?? null;
    }

    public static function lastCacheKey(): ?string
    {
        return self::$lastCacheKey;
    }

    public static function lastQueryTag(): ?string
    {
        return self::$lastQueryTag;
    }

    public static function lastXPathAxisExpr(): ?string
    {
        return self::$lastXPathAxisExpr;
    }

    /** @return array<int, string> */
    public static function xpathAxisExprTable(): array
    {
        return self::$xpathAxisExprById;
    }

    public static function registerAxisExpr(string $expr): int
    {
        $trimmed = trim($expr);
        foreach (self::$xpathAxisExprById as $id => $stored) {
            if ($stored === $trimmed) {
                return $id;
            }
        }
        $id = ++self::$nextXPathAxisId;
        self::$xpathAxisExprById[$id] = $trimmed;

        return $id;
    }

    /** Clear stale query state when a non-XPath DOMNodeList is accessed (#32620). */
    public static function clearQueryState(): void
    {
        self::$lastCacheKey = null;
        self::$lastQueryTag = null;
        self::$lastXPathAxisExpr = null;
    }

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $exprLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $exprLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }

        // Invalid literal → warning + false (php-src xpath.c; #22721). Prefer host Zend
        // when available so we do not mis-classify unimplemented-but-valid paths.
        $invalid = self::tryHostInvalidExpressionFalse($context, $xml, $exprLit, 'query');
        if (null !== $invalid) {
            return $invalid;
        }

        // Relative `@*` / `@name` with explicit context — host-fold via prior //tag (#32003 AOT).
        if (\count($args) >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $relAttr = self::tryHostRelativeAttributeAxisCompileTime($context, $xml, $exprLit);
            if (null !== $relAttr) {
                return $relAttr;
            }
        }

        // Named XPath axes / `..` — host Zend node-set at compile time (#31773).
        // User-script AOT ABI for these paths aborts; VM/JIT use VmDomXPath.
        $treeAxis = self::tryHostTreeAxisCompileTime($context, $xml, $exprLit);
        if (null !== $treeAxis) {
            return $treeAxis;
        }

        // Namespace axis lengths at compile time (#20206) — avoid ABI fallback segfault.
        $nsCount = DomParseSimpleXmlJitHelper::countNamespaceAxisArgv($xml, $exprLit);
        if (null !== $nsCount) {
            self::$lastCacheKey = null;
            self::$lastQueryTag = null;

            return self::boxNodeList($context, $nsCount);
        }

        // Relative `.` / `.//tag` with context node (#20257 / #31738) — avoid AOT context ABI.
        $relative = self::tryRelativeCompileTime($context, $xml, $exprLit);
        if (null !== $relative) {
            return $relative;
        }

        // //tag[@attr='v'|N]/@name — predicate then attribute axis (#21148, #24333).
        if (preg_match(
            '~^//([*\w][\w:-]*)\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\]/@([\w.-]+)$~',
            trim($exprLit),
            $axisMatches
        )) {
            $numeric = isset($axisMatches[4]) && '' !== $axisMatches[4];
            $predValue = $numeric ? $axisMatches[4] : ($axisMatches[3] ?? '');
            $count = DomParseSimpleXmlJitHelper::countMatchingTagAttributeAxisArgv(
                $xml,
                $axisMatches[1],
                $axisMatches[2],
                $predValue,
                $axisMatches[5],
                $numeric
            );
            self::$lastCacheKey = null;
            self::$lastQueryTag = null;
            // XPath NodeLists are snapshots — force count rewrite (#28647).
            DomUserScriptLiveTagListLlvm::initCount($context, $axisMatches[1], $count, true);

            return self::boxNodeList($context, $count);
        }

        if (!preg_match(
            '~^//([*\w][\w:-]*)(?:\[@([^\]=]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\])?$~',
            trim($exprLit),
            $matches
        )) {
            return null;
        }
        $tag = $matches[1];
        $registeredNs = self::registeredNamespaces();
        if (!isset($matches[2]) || '' === $matches[2]) {
            $count = DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, $tag, $registeredNs);
            if (null === $count) {
                // Undefined prefix — host invalid path above should have caught this; fall through.
                return null;
            }
            self::$lastCacheKey = null;
            // Prefixed QNames are not live getElementsByTagName keys (#29139).
            self::$lastQueryTag = false === strpos($tag, ':') ? strtolower($tag) : null;
            DomUserScriptLiveTagListLlvm::initCount($context, $tag, $count, true);

            return self::boxNodeList($context, $count);
        }
        // Attribute predicates on namespaced tags: require null-NS unprefixed match
        // or fall through when the tag is prefixed (#29139).
        if (false !== strpos($tag, ':')) {
            return null;
        }
        $numeric = isset($matches[4]) && '' !== $matches[4];
        $attrValue = $numeric ? $matches[4] : ($matches[3] ?? '');
        $xpathCount = DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, $tag, $registeredNs);
        $naiveCount = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        if (0 === $xpathCount) {
            // Default-NS elements must not match unprefixed //tag[@attr] (#29139).
            self::$lastCacheKey = null;
            self::$lastQueryTag = null;
            DomUserScriptLiveTagListLlvm::initCount($context, $tag, 0, true);

            return self::boxNodeList($context, 0);
        }
        if ($xpathCount !== $naiveCount) {
            // Mixed null-NS + default-NS same local name — naive attr scan is unsafe.
            return null;
        }
        $matched = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
            $xml,
            $tag,
            $matches[2],
            $attrValue,
            $numeric
        );
        if (null === $matched) {
            self::$lastCacheKey = null;
            self::$lastQueryTag = null;
            DomUserScriptLiveTagListLlvm::initCount($context, $tag, 0, true);

            return self::boxNodeList($context, 0);
        }
        [$count, $text, $materializeTag] = $matched + [2 => $tag];
        self::$lastCacheKey = strtolower($tag.'@'.$matches[2].'='.$attrValue.($numeric ? '#n' : ''));
        // Predicate lists keep the first-match element cache; do not use unfiltered nth-tag (#27275).
        self::$lastQueryTag = null;
        DomUserScriptLiveTagListLlvm::initCount($context, $tag, $count, true);
        $element = JitDomCreateElement::materializeElementWithTextContent($context, $materializeTag, $text);
        $cacheKey = $context->builder->load(
            $context->constantStringFromString(self::$lastCacheKey)
        );
        $nullDoc = $context->getTypeFromString('__object__*')->constNull();
        DomUserScriptElementCacheLlvm::store($context, $nullDoc, $cacheKey, $element);

        return self::boxNodeList($context, $count);
    }

    private static function boxNodeList(Context $context, int $length, int $axisId = 0): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NODELIST);
        if (!$objectType->hasProperty($classId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($classId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($classId, VmDom::PROP_XPATH_AXIS_ID)) {
            $objectType->defineProperty($classId, VmDom::PROP_XPATH_AXIS_ID, JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($classId, VmDom::PROP_XPATH_SNAPSHOT)) {
            $objectType->defineProperty($classId, VmDom::PROP_XPATH_SNAPSHOT, JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($classId, 'length')) {
            $objectType->defineProperty($classId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        $list = $objectType->allocate($classId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, self::CLASS_NODELIST, 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $axisIdVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($axisId, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, self::CLASS_NODELIST, VmDom::PROP_XPATH_AXIS_ID),
            $axisIdVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $snapshotVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, self::CLASS_NODELIST, VmDom::PROP_XPATH_SNAPSHOT),
            $snapshotVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $snapXml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $snapXml) {
            self::$xpathSnapshotXmlByAxisId[$axisId] = $snapXml;
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $list
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * Relative abbreviated attribute axis with context node (#32003 AOT evaluate/query follow-up).
     *
     * When context is item(0) from the immediately preceding //tag query, fold to //tag/@…
     * so user-script AOT avoids the context-less XPath ABI (returns empty / SIGSEGV).
     */
    private static function tryHostRelativeAttributeAxisCompileTime(
        Context $context,
        string $xml,
        string $exprLit
    ): ?Value {
        $trimmed = trim($exprLit);
        if (!preg_match('~^@(?:[\w.*:-]+|\*)$~', $trimmed)) {
            return null;
        }
        $queryTag = self::$lastQueryTag;
        if (null === $queryTag || '' === $queryTag || false !== strpos($queryTag, ':')) {
            return null;
        }

        return self::tryHostTreeAxisCompileTime($context, $xml, '//'.$queryTag.'/'.$trimmed);
    }

    /**
     * Compile-time relative location paths from documentElement context (#20257 / #31738).
     */
    private static function tryRelativeCompileTime(Context $context, string $xml, string $exprLit): ?Value
    {
        $trimmed = trim($exprLit);
        if ('.' === $trimmed) {
            // Self axis from documentElement — cache root so item(0) is not firstChild (#31738).
            if (!preg_match('~<[?]xml[^>]*>\s*<([A-Za-z_][\w:.-]*)~', $xml, $rootMatch)
                && !preg_match('~^\s*<([A-Za-z_][\w:.-]*)~', $xml, $rootMatch)
            ) {
                return null;
            }
            $rootName = $rootMatch[1];
            self::$lastCacheKey = 'xpath-self-dot';
            self::$lastQueryTag = null;
            $element = JitDomCreateElement::materializeElementWithTextContent($context, $rootName, '');
            $cacheKey = $context->builder->load(
                $context->constantStringFromString(self::$lastCacheKey)
            );
            $nullDoc = $context->getTypeFromString('__object__*')->constNull();
            DomUserScriptElementCacheLlvm::store($context, $nullDoc, $cacheKey, $element);

            return self::boxNodeList($context, 1);
        }
        if (!preg_match('~^\.//([*\w][\w:-]*)$~', $trimmed, $matches)) {
            return null;
        }
        $tag = $matches[1];
        $registeredNs = self::registeredNamespaces();
        $count = DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, $tag, $registeredNs);
        if (null === $count) {
            $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        }
        if (null === $count) {
            return null;
        }
        self::$lastCacheKey = null;
        self::$lastQueryTag = false === strpos($tag, ':') ? strtolower($tag) : null;
        DomUserScriptLiveTagListLlvm::initCount($context, $tag, $count, true);

        return self::boxNodeList($context, $count);
    }

    /**
     * Compile-time host Zend query for `axis::` / `..` location paths (#31773).
     *
     * User-script AOT ABI aborts on these expressions; VM/JIT use {@see VmDomXPath}.
     */
    private static function tryHostTreeAxisCompileTime(Context $context, string $xml, string $exprLit): ?Value
    {
        $trimmed = trim($exprLit);
        // Host-fold named axes / `..` (#31773), `//*[last()]` / `[position()…]` (#31923),
        // abbreviated attribute axis `@*` / `@name` (#32003, #32032), and `or`/`and`/`not(`
        // boolean predicates (#32050). Flattened descendant `[last()]` is wrong; user-script
        // AOT ABI aborts on these paths.
        $positional = (bool) preg_match('~\[(?:last\(\)|position\(\))~i', $trimmed);
        $attrAxis = self::isHostFoldAttributeAxis($trimmed);
        // Bare relative `@axis` needs an explicit context node — handled above (#32003 AOT).
        if ($attrAxis && !str_contains($trimmed, '/')) {
            return null;
        }
        // Boolean `or`/`and`/`not(` — user-script regex only handles [@attr=v] (#32050).
        $boolExpr = str_contains($trimmed, ' or ')
            || str_contains($trimmed, ' and ')
            || str_contains($trimmed, '[not(');
        if (!str_contains($trimmed, '::') && !str_contains($trimmed, '..') && !$positional && !$attrAxis && !$boolExpr) {
            return null;
        }
        if (!\extension_loaded('dom') || !\class_exists(\DOMDocument::class, false)) {
            return null;
        }
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                restore_error_handler();

                return null;
            }
            $xpath = new \DOMXPath($doc);
            foreach (JitDomXPathRegisterUserScript::namespaces() as $prefix => $uri) {
                if ('' === $prefix) {
                    continue;
                }
                @$xpath->registerNamespace($prefix, $uri);
            }
            $list = $xpath->query($trimmed);
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
        restore_error_handler();
        if (false === $list) {
            return null;
        }
        $count = $list->length;
        self::$lastCacheKey = null;
        self::$lastQueryTag = null;
        self::$lastXPathAxisExpr = $trimmed;
        $axisId = self::registerAxisExpr($trimmed);
        if ($count > 0) {
            $first = $list->item(0);
            if ($first instanceof \DOMNode) {
                $tag = $first->nodeName;
                if ('#document' !== $tag && '' !== $tag) {
                    self::$lastCacheKey = 'xpath-axis-'.md5($trimmed);
                    $cacheKey = $context->builder->load(
                        $context->constantStringFromString(self::$lastCacheKey)
                    );
                    $nullDoc = $context->getTypeFromString('__object__*')->constNull();
                    if ($first instanceof \DOMAttr) {
                        $node = JitDomAttributeNodeNS::materializeAttrFromLiterals(
                            $context,
                            (string) ($first->namespaceURI ?? ''),
                            $first->nodeName,
                            $first->value,
                            JitDomAttributeNodeNS::attrClassForUserScriptCache()
                        );
                    } else {
                        $text = $first instanceof \DOMElement ? (string) $first->textContent : '';
                        $node = JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
                    }
                    DomUserScriptElementCacheLlvm::store($context, $nullDoc, $cacheKey, $node);
                }
            }
        }
        DomUserScriptLiveTagListLlvm::initCount($context, 'xpath-axis', $count, true);

        return self::boxNodeList($context, $count, $axisId);
    }

    /**
     * Host Zend DOMXPath::query() false → compile-time warn + false (#22721).
     * Returns null when the expression is valid or host DOM is unavailable.
     */
    public static function tryHostInvalidExpressionFalse(
        Context $context,
        string $xml,
        string $expression,
        string $method
    ): ?Value {
        if (!\extension_loaded('dom') || !\class_exists(\DOMDocument::class, false)) {
            // Heuristic fallback when host has no DOM (rare in Docker image).
            if ('' === trim($expression) || 1 === preg_match('/^[@#]$|^!+$|^\@{2,}$/', trim($expression))) {
                JitBuiltinWarning::emit($context, sprintf('DOMXPath::%s(): Invalid expression', $method));

                return self::boxFalse($context);
            }

            return null;
        }
        $warn = null;
        set_error_handler(static function (int $severity, string $message) use (&$warn): bool {
            $warn = $message;

            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                restore_error_handler();

                return null;
            }
            $xpath = new \DOMXPath($doc);
            foreach (JitDomXPathRegisterUserScript::namespaces() as $prefix => $uri) {
                if ('' === $prefix) {
                    continue;
                }
                @$xpath->registerNamespace($prefix, $uri);
            }
            $result = $xpath->query($expression);
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
        restore_error_handler();
        if (false !== $result) {
            return null;
        }
        $msg = sprintf('DOMXPath::%s(): Invalid expression', $method);
        if (\is_string($warn)) {
            if (str_contains($warn, 'Undefined namespace prefix')) {
                $msg = sprintf('DOMXPath::%s(): Undefined namespace prefix', $method);
            } elseif (str_contains($warn, 'Invalid number of arguments')) {
                $msg = sprintf('DOMXPath::%s(): Invalid number of arguments', $method);
            } elseif (preg_match('/DOMXPath::(?:query|evaluate)\(\):\s*(.+)$/', $warn, $m)) {
                $msg = sprintf('DOMXPath::%s(): %s', $method, $m[1]);
            }
        }
        JitBuiltinWarning::emit($context, $msg);

        return self::boxFalse($context);
    }

    private static function boxFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /** @return array<string, string> */
    private static function registeredNamespaces(): array
    {
        return JitDomXPathRegisterUserScript::namespaces();
    }

    /**
     * Abbreviated XPath attribute axis (@name / @*), not predicate [@attr] (#32003, #32032).
     */
    private static function isHostFoldAttributeAxis(string $expr): bool
    {
        return 1 === preg_match('~(?:^|/)@(?:[\w.*:-]+|\*)~', $expr);
    }

    /**
     * Host Zend node-set length for a folded axis/boolean XPath (#32003 item(N>0)).
     */
    public static function hostXPathAxisQueryLength(string $xml, string $expr): ?int
    {
        $list = self::hostXPathAxisQuery($xml, $expr);

        return null === $list ? null : $list->length;
    }

    /**
     * Host Zend node-set item for compile-time NodeList::item(N) (#32003).
     */
    public static function hostXPathAxisNodeAt(string $xml, string $expr, int $index): ?\DOMNode
    {
        $list = self::hostXPathAxisQuery($xml, $expr);
        if (null === $list || $index < 0 || $index >= $list->length) {
            return null;
        }

        return $list->item($index);
    }

    private static function hostXPathAxisQuery(string $xml, string $expr): ?\DOMNodeList
    {
        if (!\extension_loaded('dom') || !\class_exists(\DOMDocument::class, false)) {
            return null;
        }
        $trimmed = trim($expr);
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                restore_error_handler();

                return null;
            }
            $xpath = new \DOMXPath($doc);
            foreach (JitDomXPathRegisterUserScript::namespaces() as $prefix => $uri) {
                if ('' === $prefix) {
                    continue;
                }
                @$xpath->registerNamespace($prefix, $uri);
            }
            $list = $xpath->query($trimmed);
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
        restore_error_handler();
        if (false === $list) {
            return null;
        }

        return $list;
    }
}
