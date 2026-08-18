<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::importNode() (#19212, #32350).
 *
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode.
 * Thin-standalone AOT cannot return NestedJIT object pointers (property fetch
 * aborts; contrast adoptNode #29853 which reuses the caller-side node). Materialize
 * a user-script DOMElement instead — tag/inner XML from compile-time loadXML
 * (#32350) or loadHTML getElementById (#19212).
 */
final class JitDomImportNode
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::importNode() expects receiver and node');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_import_node_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeUserScriptMaterialize($context, $args[0]);
        }

        DomImportNodeRuntime::ensureLinked($context);
        $document = self::loadObjectArg($context, $args[0]);
        $node = self::loadObjectArg($context, $args[1]);
        $deep = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2])) {
            $deep = self::loadBoolAsInt($context, $args[2]);
        }
        $imported = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_NAME),
            $document,
            $node,
            $deep
        );

        return self::boxObjectResult($context, $imported);
    }

    public static function invokeGetAttribute(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::getAttribute() expects receiver and name');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_get_attr_cont');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $nameLit = null;
            if (JITVariable::TYPE_STRING === $args[1]->type) {
                // Prefer compile-time id for importAttribute parity.
            }
            $parsed = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
                ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
            $idLit = $parsed['id'] ?? 'target';
            $str = $context->builder->load($context->constantStringFromString($idLit));
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $str
            );

            return JitValueBox::normalizeValuePtr($context, $ptr);
        }

        DomImportNodeRuntime::ensureGetAttributeLinked($context);
        $element = self::loadObjectArg($context, $args[0]);
        $name = self::loadStringArg($context, $args[1]);
        $value = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE),
            $element,
            $name
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $value
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    /**
     * Thin AOT: clone via user-script materialize (nodeName/tagName/INNER_XML slots).
     * NestedJIT object returns abort on property fetch (#29853 / #32350).
     */
    private static function invokeUserScriptMaterialize(
        Context $context,
        JITVariable $documentVar
    ): Value {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        $html = JitDomLoadHTMLUserScript::lastGetElementByIdHit()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsed();
        $tag = 'div';
        $text = '';
        $inner = '';
        $id = 'target';
        $fromXml = false;
        if (null !== $xml) {
            $root = self::parseCompileTimeXmlRoot($xml);
            if (null !== $root) {
                $tag = $root['tag'];
                $inner = $root['inner'];
                $fromXml = true;
            }
        }
        if (!$fromXml && null !== $html) {
            $tag = $html['tag'] ?? $tag;
            $text = $html['text'] ?? '';
            $id = $html['id'] ?? $id;
        }

        $element = JitDomCreateElement::materializeForUserScriptDocument(
            $context,
            $documentVar,
            $tag,
            $text
        );
        if ('' !== $inner) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $element, $inner);
        }
        if (!$fromXml) {
            self::storeElementInIdMap($context, $documentVar, $id, $element);
        }

        return self::boxObjectResult($context, $element);
    }

    /**
     * Root tag + child markup from a compile-time loadXML literal (#32350).
     *
     * @return array{tag: string, inner: string}|null
     */
    private static function parseCompileTimeXmlRoot(string $xml): ?array
    {
        $xml = ltrim($xml);
        if (str_starts_with($xml, '<?xml')) {
            $end = strpos($xml, '?>');
            if (false !== $end) {
                $xml = ltrim(substr($xml, $end + 2));
            }
        }
        if (1 === preg_match('/^<([A-Za-z_][\w:.-]*)\b[^>]*\/>/', $xml, $m)) {
            return ['tag' => $m[1], 'inner' => ''];
        }
        if (1 === preg_match('/^<([A-Za-z_][\w:.-]*)\b[^>]*>(.*)<\/\1\s*>/s', $xml, $m)) {
            return ['tag' => $m[1], 'inner' => $m[2]];
        }

        return null;
    }

    private static function storeElementInIdMap(
        Context $context,
        JITVariable $documentVar,
        string $idLit,
        Value $element
    ): void {
        $document = self::loadObjectArg($context, $documentVar);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($classId, VmDom::PROP_ELEMENT_ID_MAP)) {
            $objectType->defineProperty($classId, VmDom::PROP_ELEMENT_ID_MAP, JITVariable::TYPE_VALUE);
        }
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

        throw new \LogicException('DOMDocument::importNode() expects object nodes');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }

    private static function loadBoolAsInt(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null !== $arg->compileTimeLong) {
            return $i64->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $v = $context->helper->loadValue($arg);

            return $context->builder->zExt($v, $i64);
        }
        $valPtr = JitValueBox::valuePtrFromVariable($context, $arg);
        // No __value__readBool — bool payload is int8 at value[0] (#29109 / #21892).
        $boolByte = JitValueBox::readBoolByte($context, $valPtr);

        return $context->builder->zExt($boolByte, $i64);
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
