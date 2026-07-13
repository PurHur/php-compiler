<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMXPath::query() (#18493). */
final class JitDomXPathQueryUserScript
{
    private const CLASS_NODELIST = 'DOMNodeList';

    private static ?string $lastCacheKey = null;

    public static function lastCacheKey(): ?string
    {
        return self::$lastCacheKey;
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
        if (!preg_match(
            '~^//([*\w][\w:-]*)(?:\[@([^\]=]+)=["\']([^"\']*)["\']\])?$~',
            trim($exprLit),
            $matches
        )) {
            return null;
        }
        $tag = $matches[1];
        if (!isset($matches[2])) {
            self::$lastCacheKey = null;
            DomUserScriptLiveTagListLlvm::initCount(
                $context,
                $tag,
                DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag)
            );

            return self::boxNodeList($context);
        }
        $matched = DomParseSimpleXmlJitHelper::matchDescendantAttributeArgv(
            $xml,
            $tag,
            $matches[2],
            $matches[3]
        );
        if (null === $matched) {
            self::$lastCacheKey = null;
            DomUserScriptLiveTagListLlvm::initCount($context, $tag, 0);

            return self::boxNodeList($context);
        }
        [$count, $text] = $matched;
        self::$lastCacheKey = strtolower($tag.'@'.$matches[2].'='.$matches[3]);
        DomUserScriptLiveTagListLlvm::initCount($context, $tag, $count);
        $element = JitDomCreateElement::materializeElementWithTextContent($context, $tag, $text);
        $cacheKey = $context->builder->load(
            $context->constantStringFromString(self::$lastCacheKey)
        );
        $nullDoc = $context->getTypeFromString('__object__*')->constNull();
        DomUserScriptElementCacheLlvm::store($context, $nullDoc, $cacheKey, $element);

        return self::boxNodeList($context);
    }

    private static function boxNodeList(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_NODELIST);
        $list = $objectType->allocate($classId);
        $objectType->markObjectConstructed($list);
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
