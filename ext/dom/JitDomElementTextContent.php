<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomElementTextContentRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::$textContent / $nodeValue (#17954, #23251).
 *
 * User-script AOT: detach held children via parentNode slots, then install a text child.
 * DomRegistry-backed nodes also call writeTextContent / writeNodeValue.
 */
final class JitDomElementTextContent
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_NODE_VALUE = 'nodeValue';

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        return self::fetchNamed($objectType, $obj, self::PROP_TEXT_CONTENT);
    }

    public static function fetchNamed(Object_ $objectType, Value $obj, string $propName): JITVariable
    {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);

        // User-script AOT: always read the seeded STRING slot. NestedJIT of
        // textContentArgv SIGSEGVs after c:main_before_php when there was no
        // loadXML (createElement($name, $value) — #32292 / php-src document.c).
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $classId = $objectType->lookup(self::CLASS_ELEMENT);
            $slotProp = 'nodevalue' === $propLc ? self::PROP_NODE_VALUE : self::PROP_TEXT_CONTENT;
            if (!$objectType->hasProperty($classId, $slotProp)) {
                $objectType->defineProperty($classId, $slotProp, JITVariable::TYPE_STRING);
            }
            $var = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $obj,
                self::CLASS_ELEMENT,
                $slotProp,
                $classId
            );
            $var->objectPropertyReceiver = $obj;
            $var->objectPropertyName = $slotProp;
            $var->objectPropertyClassName = self::CLASS_ELEMENT;
            $var->objectPropertyType = JITVariable::TYPE_STRING;

            return $var;
        }

        DomElementTextContentRuntime::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction(DomElementTextContentRuntime::ABI_NAME),
            $obj
        );

        $var = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
        $var->objectPropertyReceiver = $obj;
        $var->objectPropertyName = $propName;
        $var->objectPropertyClassName = self::CLASS_ELEMENT;
        $var->objectPropertyType = JITVariable::TYPE_STRING;

        return $var;
    }

    public static function isDomElementTextContent(string $classLc, string $propLc): bool
    {
        $propLc = strtolower($propLc);
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (!\in_array($propLc, ['textcontent', 'nodevalue'], true)) {
            return false;
        }
        if ('domelement' === $classLc) {
            return true;
        }
        // User-script AOT often loses DOMElement type on temps after documentElement assign
        // (CFG userType → object); still route textContent writes through the DOM bridge (#23251).
        if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && \in_array($classLc, ['object', 'stdclass', ''], true)
        ) {
            return true;
        }

        return false;
    }

    /** Dom\Attr / DOMAttr::$value|nodeValue — sync TYPE_STRING slots (#27108). */
    public static function isDomAttrValueProperty(string $classLc, string $propLc): bool
    {
        $propLc = strtolower($propLc);
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));
        if (\in_array($classLc, ['dom\\attr', 'domattr'], true)
            && \in_array($propLc, ['value', 'nodevalue'], true)
        ) {
            return true;
        }
        // Temps often lose Dom\Attr as CFG `object`. Prefer `$value` only — `$nodeValue` on
        // `object` still belongs to the DOMElement textContent bridge (#23251).
        return 'value' === $propLc
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
            && null !== JitDomLoadXMLUserScript::lastDocumentClass()
            && str_starts_with((string) JitDomLoadXMLUserScript::lastDocumentClass(), 'Dom\\')
            && \in_array($classLc, ['object', 'stdclass', ''], true);
    }

    /**
     * @return bool true when the store was handled (caller must skip propertyStore)
     */
    public static function tryEmitStore(Context $context, JITVariable $lvalue, JITVariable $value): bool
    {
        $prop = $lvalue->objectPropertyName ?? '';
        $class = $lvalue->objectPropertyClassName ?? '';
        $propLc = strtolower($prop);
        $classLc = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        if (null === $lvalue->objectPropertyReceiver) {
            return false;
        }

        $receiver = $lvalue->objectPropertyReceiver;

        // Living / classic Attr value|nodeValue — direct TYPE_STRING slot write (#27108).
        // Must run before isDomElementTextContent so Attr::$nodeValue is not treated as Element.
        if (self::isDomAttrValueProperty($classLc, $propLc)) {
            $str = self::loadStringValue($context, $value);
            $attrClass = ('domattr' === $classLc) ? 'DOMAttr' : 'Dom\\Attr';
            JitDomAttributeNodeNS::ensureLivingAttrMethods($context);
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            foreach (['value', 'nodeValue'] as $syncProp) {
                $context->type->object->propertyStore(
                    $context->type->object->propertySlotFor($receiver, $attrClass, $syncProp),
                    new JITVariable(
                        $context,
                        JITVariable::TYPE_STRING,
                        JITVariable::KIND_VALUE,
                        $owned
                    ),
                    JITVariable::TYPE_STRING
                );
            }

            return true;
        }

        if (!self::isDomElementTextContent($classLc, $propLc)) {
            return false;
        }

        $str = self::loadStringValue($context, $value);

        if (JitDomDocumentMethodKernel::shouldUse($context)
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
            self::emitUserScriptDetachAndReplace($context, $receiver, $str);
            if (null !== $lvalue->objectPropertySlot) {
                $context->type->object->propertyStore(
                    $lvalue->objectPropertySlot,
                    new JITVariable(
                        $context,
                        JITVariable::TYPE_STRING,
                        JITVariable::KIND_VALUE,
                        $str
                    ),
                    JITVariable::TYPE_STRING
                );
            }

            return true;
        }

        DomElementTextContentRuntime::ensureWriteLinked($context);
        $abi = 'nodevalue' === $propLc
            ? DomElementTextContentRuntime::ABI_WRITE_NODE_VALUE
            : DomElementTextContentRuntime::ABI_WRITE_TEXT_CONTENT;
        $context->builder->call(
            $context->lookupFunction($abi),
            $receiver,
            $str
        );

        return true;
    }

    /**
     * Detach children like php_libxml_node_free_list (#23251, #23892).
     *
     * First held child keeps a null parentNode; later siblings are marked freed so
     * property access raises dom_objects_not_found(). Then install a text stand-in.
     */
    private static function emitUserScriptDetachAndReplace(
        Context $context,
        Value $receiver,
        Value $textStr
    ): void {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }

        $objPtrTy = $context->getTypeFromString('__object__*');
        $firstObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $receiver,
            VmDom::PROP_FIRST_CHILD
        );
        $lastObj = self::loadChildObjectFromSlot(
            $context,
            $objectType,
            $receiver,
            VmDom::PROP_LAST_CHILD
        );

        // Null parentNode on firstChild (retained user handle).
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $detachFirst = BasicBlockHelper::append($context, 'dom_tc_detach_first');
        $afterFirst = BasicBlockHelper::append($context, 'dom_tc_after_first');
        $context->builder->branchIf($firstNull, $afterFirst, $detachFirst);
        $context->builder->positionAtEnd($detachFirst);
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $nullSlot
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($firstObj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $nullVar,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($afterFirst);
        $context->builder->positionAtEnd($afterFirst);

        // Mark lastChild freed when it is a distinct sibling (#23892).
        $lastNull = $context->builder->icmp(Builder::INT_EQ, $lastObj, $objPtrTy->constNull());
        $sameAsFirst = $context->builder->icmp(Builder::INT_EQ, $lastObj, $firstObj);
        $skipLast = $context->builder->or($lastNull, $sameAsFirst);
        $markLast = BasicBlockHelper::append($context, 'dom_tc_mark_last_freed');
        $afterLast = BasicBlockHelper::append($context, 'dom_tc_after_last');
        $context->builder->branchIf($skipLast, $afterLast, $markLast);
        $context->builder->positionAtEnd($markLast);
        JitDomParentNodeProperty::markFreed($context, $lastObj);
        $context->builder->branch($afterLast);
        $context->builder->positionAtEnd($afterLast);

        $textNode = JitDomCreateTextNode::materialize($context);
        if (!$objectType->hasProperty($elementClassId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($elementClassId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $textJitStr = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $textStr
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($textNode, self::CLASS_ELEMENT, self::PROP_TEXT_CONTENT),
            $textJitStr,
            JITVariable::TYPE_STRING
        );
        $textJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $textNode
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiver, 'DOMElement', VmDom::PROP_FIRST_CHILD),
            $textJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiver, 'DOMElement', VmDom::PROP_LAST_CHILD),
            $textJit,
            JITVariable::TYPE_VALUE
        );
        $parentJit = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $receiver
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($textNode, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );
        JitDomDocumentElement::storeChildNodesLength($context, $receiver, 1);
        // Prefer empty inner markup so saveXML falls back to textContent (#26757 / #23251).
        JitDomCreateElement::storeUserScriptInnerXml($context, $receiver, '');
    }

    /** Load __object__* from a DOMNode firstChild/lastChild TYPE_VALUE slot (or null). */
    private static function loadChildObjectFromSlot(
        Context $context,
        Object_ $objectType,
        Value $receiver,
        string $prop
    ): Value {
        $childSlot = $objectType->propertySlotFor($receiver, 'DOMNode', $prop);
        $slotPtr = $context->builder->load($childSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_read');
        $merge = BasicBlockHelper::append($context, 'dom_tc_load_'.$prop.'_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $childObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($childObj, $readBlock);

        return $phi;
    }

    public static function loadObjectFromReceiver(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOMElement::$textContent receiver must be an object');
    }

    private static function loadStringValue(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_STRING === $value->type) {
            return $context->helper->loadValue($value);
        }
        if (JITVariable::TYPE_NULL === $value->type || $value->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_VALUE === $value->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $value)
            );
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($value) ?? $value->compileTimeString;
        if (null !== $lit) {
            return $context->builder->load($context->constantStringFromString($lit));
        }

        return $context->builder->load($context->constantStringFromString(''));
    }
}
