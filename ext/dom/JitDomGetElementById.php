<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::getElementById() (#17954).
 *
 * Reads the document's mirrored id map populated by {@see VmDom::syncElementIdMapProperty()}.
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class JitDomGetElementById
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        return self::invokeLookup($context, ...$args);
    }

    private static function invokeLookup(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects receiver and element id');
        }

        self::ensureDocumentPropertyLayout($context);

        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            $document = self::loadObjectArg($context, $args[0]);
            $idStr = self::loadStringArg($context, $args[1]);

            return self::lookupUserScriptWithCacheFallback(
                $context,
                self::lookupIdMapValueBox($context, $document, $idStr),
                $idStr
            );
        }

        $document = self::loadObjectArg($context, $args[0]);
        $idStr = self::loadStringArg($context, $args[1]);

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

        return JitValueBox::valuePtrFromVariable(
            $context,
            HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr)
        );
    }

    private static function lookupIdMapValueBox(Context $context, Value $document, Value $idStr): JITVariable
    {
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

        return HashTableHelper::readStringKeyToValueBox($context, $ht, $idStr);
    }

    private static function lookupUserScriptWithCacheFallback(
        Context $context,
        JITVariable $foundVar,
        Value $idStr
    ): Value {
        $objPtr = $context->getTypeFromString('__object__*');
        $valPtr = JitValueBox::valuePtrFromVariable($context, $foundVar);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $isObject = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_OBJECT, false)
        );
        $mapBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_map');
        $cacheBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache');
        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_done');
        $resultSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isObject, $mapBlock, $cacheBlock);

        $context->builder->positionAtEnd($mapBlock);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $valPtr), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($cacheBlock);
        $cached = DomUserScriptElementCacheLlvm::lookupObject($context, $idStr);
        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gei_us_cache_after_lookup');
        $objPtr = $context->getTypeFromString('__object__*');
        $isNullObj = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cached,
            $objPtr->constNull()
        );
        $cacheNullBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_null');
        $cacheObjBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_obj');
        $cacheBoxDone = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'dom_gei_us_cache_box_done');
        $cacheBoxSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $context->builder->branchIf($isNullObj, $cacheNullBlock, $cacheObjBlock);
        $context->builder->positionAtEnd($cacheNullBlock);
        $nullBoxed = self::boxNullResult($context);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $nullBoxed), $cacheBoxSlot);
        $context->builder->branch($cacheBoxDone);
        $context->builder->positionAtEnd($cacheObjBlock);
        $objBoxed = self::boxObjectResult($context, $cached);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $objBoxed), $cacheBoxSlot);
        $context->builder->branch($cacheBoxDone);
        $context->builder->positionAtEnd($cacheBoxDone);
        $boxed = $context->builder->load($cacheBoxSlot);
        $context->builder->store(JitValueBox::normalizeValuePtr($context, $boxed), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->load($resultSlot);
        \PHPCompiler\JIT\BasicBlockHelper::branchToFreshContinue($context, 'after_dom_gei_us_lookup');

        return $result;
    }

    private static function boxObjectResult(Context $context, Value $element): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
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

        throw new \LogicException('DOMDocument::getElementById() receiver must be an object');
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
}
