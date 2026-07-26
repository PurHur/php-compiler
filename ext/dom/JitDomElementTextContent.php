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

        if (JitDomDocumentMethodKernel::shouldUse($context)
            && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
        ) {
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
        if (!\in_array(strtolower($propLc), ['textcontent', 'nodevalue'], true)) {
            return false;
        }
        $classLc = strtolower($classLc);
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

    /**
     * @return bool true when the store was handled (caller must skip propertyStore)
     */
    public static function tryEmitStore(Context $context, JITVariable $lvalue, JITVariable $value): bool
    {
        $prop = $lvalue->objectPropertyName ?? '';
        $class = $lvalue->objectPropertyClassName ?? '';
        if (!self::isDomElementTextContent(strtolower($class), strtolower($prop))) {
            return false;
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return false;
        }

        $str = self::loadStringValue($context, $value);
        $receiver = $lvalue->objectPropertyReceiver;

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
        $abi = 'nodevalue' === strtolower($prop)
            ? DomElementTextContentRuntime::ABI_WRITE_NODE_VALUE
            : DomElementTextContentRuntime::ABI_WRITE_TEXT_CONTENT;
        $context->builder->call(
            $context->lookupFunction($abi),
            $receiver,
            $str
        );

        return true;
    }

    /** Null parentNode on current children, install a single text stand-in child (#23251). */
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

        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            // firstChild/lastChild are TYPE_VALUE slots holding __value__ boxes (#23251).
            $childSlot = $objectType->propertySlotFor($receiver, 'DOMNode', $prop);
            $slotPtr = $context->builder->load($childSlot);
            $voidPtr = $context->getTypeFromString('void*');
            $isNullSlot = $context->builder->icmp(
                Builder::INT_EQ,
                $slotPtr,
                $voidPtr->constNull()
            );
            $detachBlock = BasicBlockHelper::append($context, 'dom_tc_detach_'.$prop);
            $contBlock = BasicBlockHelper::append($context, 'dom_tc_cont_'.$prop);
            $context->builder->branchIf($isNullSlot, $contBlock, $detachBlock);
            $context->builder->positionAtEnd($detachBlock);
            $valuePtr = $context->builder->pointerCast(
                $slotPtr,
                $context->getTypeFromString('__value__*')
            );
            $childObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $isNullObj = $context->builder->icmp(
                Builder::INT_EQ,
                $childObj,
                $context->getTypeFromString('__object__*')->constNull()
            );
            $doDetach = BasicBlockHelper::append($context, 'dom_tc_do_detach_'.$prop);
            $context->builder->branchIf($isNullObj, $contBlock, $doDetach);
            $context->builder->positionAtEnd($doDetach);
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
                $objectType->propertySlotFor($childObj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
                $nullVar,
                JITVariable::TYPE_VALUE
            );
            $context->builder->branch($contBlock);
            $context->builder->positionAtEnd($contBlock);
        }

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
            $objectType->propertySlotFor($receiver, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $textJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($receiver, 'DOMNode', VmDom::PROP_LAST_CHILD),
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
