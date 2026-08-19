<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMDocument::getElementsByTagName() (#18478). */
final class JitDomGetElementsByTagNameUserScript
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private static ?string $lastNsUri = null;

    private static ?string $lastNsLocal = null;

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    /** @return null|array{0: string, 1: string} namespace URI + localName from last NS query (#32415). */
    public static function lastNsQuery(): ?array
    {
        if (null === self::$lastNsUri || null === self::$lastNsLocal) {
            return null;
        }

        return [self::$lastNsUri, self::$lastNsLocal];
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastNsUri = null;
        self::$lastNsLocal = null;
        if (\count($args) < 2) {
            return null;
        }
        // Soft-null under non-strict is '' (Z_PARAM_STR); keep UserScript path so thin-AOT
        // does not call the empty-tag ABI bridge that segfaults (#29959).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return null;
            }
            $tagLit = '';
        } else {
            $tagLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $tagLit) {
                return null;
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tagLit);
        // Preserve live appendChild increments when re-querying the same tag (#28605).
        DomUserScriptLiveTagListLlvm::initCount($context, $tagLit, $count);

        return self::boxNodeList($context, $count);
    }

    /**
     * DOMDocument::getElementsByTagNameNS() — compile-time live list (#32415).
     *
     * php-src: ext/dom/php_dom.c PHP_METHOD(DOMDocument, getElementsByTagNameNS).
     */
    public static function tryInvokeNS(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3) {
            return null;
        }
        $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $nsLit && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            $nsLit = '';
        }
        if ($context->callerStrictTypes && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
            return null;
        }
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $nsLit || null === $localLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        $count = DomParseSimpleXmlJitHelper::countElementsByTagNameNSArgv($xml, $nsLit, $localLit);
        self::$lastNsUri = $nsLit;
        self::$lastNsLocal = $localLit;
        DomUserScriptLiveTagListLlvm::initCount($context, 'ns|'.$nsLit.'|'.$localLit, $count);

        return self::boxNodeList($context, $count);
    }

    /**
     * DOMElement::getElementsByTagName() — descendants of documentElement (#32454).
     *
     * php-src: ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagName).
     */
    public static function tryInvokeFromElement(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastNsUri = null;
        self::$lastNsLocal = null;
        if (\count($args) < 2) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return null;
            }
            $tagLit = '';
        } else {
            $tagLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $tagLit) {
                return null;
            }
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        $count = DomParseSimpleXmlJitHelper::countDescendantTagArgv($inner, $tagLit);
        DomUserScriptLiveTagListLlvm::initCount($context, $tagLit, $count);

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
}
