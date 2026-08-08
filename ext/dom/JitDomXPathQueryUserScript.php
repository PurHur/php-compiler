<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
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

    public static function lastCacheKey(): ?string
    {
        return self::$lastCacheKey;
    }

    public static function lastQueryTag(): ?string
    {
        return self::$lastQueryTag;
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

        // Namespace axis lengths at compile time (#20206) — avoid ABI fallback segfault.
        $nsCount = DomParseSimpleXmlJitHelper::countNamespaceAxisArgv($xml, $exprLit);
        if (null !== $nsCount) {
            self::$lastCacheKey = null;
            self::$lastQueryTag = null;

            return self::boxNodeList($context, $nsCount);
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
        [$count, $text] = $matched;
        self::$lastCacheKey = strtolower($tag.'@'.$matches[2].'='.$attrValue.($numeric ? '#n' : ''));
        // Predicate lists keep the first-match element cache; do not use unfiltered nth-tag (#27275).
        self::$lastQueryTag = null;
        DomUserScriptLiveTagListLlvm::initCount($context, $tag, $count, true);
        $element = JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
        $cacheKey = $context->builder->load(
            $context->constantStringFromString(self::$lastCacheKey)
        );
        $nullDoc = $context->getTypeFromString('__object__*')->constNull();
        DomUserScriptElementCacheLlvm::store($context, $nullDoc, $cacheKey, $element);

        return self::boxNodeList($context, $count);
    }

    private static function boxNodeList(Context $context, int $length): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NODELIST);
        $list = $objectType->allocate($classId);
        $objectType->markObjectConstructed($list);
        if (!$objectType->hasProperty($classId, 'length')) {
            $objectType->defineProperty($classId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
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
}
