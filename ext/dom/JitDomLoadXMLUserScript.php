<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMDocument::loadXML() (#18268, #19211). */
final class JitDomLoadXMLUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    private static ?string $lastCompileTimeXml = null;

    public static function lastCompileTimeXml(): ?string
    {
        return self::$lastCompileTimeXml;
    }

    public static function rememberCompileTimeXml(string $xml): void
    {
        self::$lastCompileTimeXml = $xml;
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

        // Non-default parse options (e.g. LIBXML_NOENT) need full VmDom::loadXML (#19796).
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $opt = $args[2]->compileTimeLong;
            if (null === $opt || 0 !== $opt) {
                return null;
            }
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $lit || '' === trim($lit)) {
            return null;
        }

        self::$lastCompileTimeXml = $lit;
        foreach (DomParseSimpleXmlIdsJitHelper::parseIndexedElementIds($lit) as $parsed) {
            self::materializeIndexedElement($context, $args[0], $parsed);
        }

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt(1, false), $i32)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * @param array{tag: string, id: string, text: string} $parsed
     */
    private static function materializeIndexedElement(
        Context $context,
        JITVariable $receiver,
        array $parsed
    ): void {
        $document = self::loadObjectArg($context, $receiver);
        $element = JitDomCreateElement::invoke(
            $context,
            $receiver,
            new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($parsed['tag']))
            )
        );
        self::storeElementTextContent($context, $element, $parsed['text']);
        self::storeElementInIdMap($context, $document, $parsed['id'], $element);
        $idStr = $context->builder->load($context->constantStringFromString($parsed['id']));
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
        self::pinUserScriptLoadSideEffects($context);
    }

    /** Keep id-map/cache writes when loadXML() return is discarded (#19211). */
    private static function pinUserScriptLoadSideEffects(Context $context): void
    {
        foreach (['__phpc_dom_us_ok', '__phpc_dom_us_id', '__phpc_dom_us_elem'] as $globalName) {
            $global = $context->module->getNamedGlobal($globalName);
            if (null === $global) {
                continue;
            }
            $context->builder->load($global);
        }
    }

    private static function storeElementInIdMap(
        Context $context,
        Value $document,
        string $idLit,
        Value $element
    ): void {
        self::ensureDocumentPropertyLayout($context);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        $mapVar = ObjectInstancePropertyLlvm::propertyFetchOrdinary(
            $objectType,
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP,
            $classId
        );
        $ht = HashTableHelper::readHashtableFromValueBox($context, $mapVar);
        $idStr = $context->builder->load($context->constantStringFromString($idLit));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $idStr,
            $element
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $propSlot = $objectType->propertySlotFor(
            $document,
            self::CLASS_DOCUMENT,
            VmDom::PROP_ELEMENT_ID_MAP
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $objectType->propertyStore($propSlot, $propVar, JITVariable::TYPE_VALUE);
        DomUserScriptElementCacheLlvm::store($context, $document, $idStr, $element);
    }

    private static function storeElementTextContent(Context $context, Value $element, string $textLit): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($classId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $textStr = $context->builder->load($context->constantStringFromString($textLit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, self::PROP_TEXT_CONTENT),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function ensureDocumentPropertyLayout(Context $context): void
    {
        $object = $context->type->object;
        $classId = $object->lookup(self::CLASS_DOCUMENT);
        if ($object->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            return;
        }
        $object->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMDocument::loadXML() receiver must be an object');
    }
}
