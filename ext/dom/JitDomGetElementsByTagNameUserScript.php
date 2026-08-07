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
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tagLit);
        // Preserve live appendChild increments when re-querying the same tag (#28605).
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
