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

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $tagLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $tagLit) {
            return null;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        DomUserScriptLiveTagListLlvm::initCount(
            $context,
            $tagLit,
            DomParseSimpleXmlJitHelper::countTagArgv($xml, $tagLit)
        );

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
