<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * DOMAttr child/sibling edges for user-script AOT (#20501).
 *
 * Avoids writing nextSibling/firstChild onto the Attr object layout — that OOBs
 * thin-AOT allocations (#35227). Uses compile-time Attr identity stamps
 * ({@see JITVariable::$compileTimeDomAttrLocalName}) plus live {@see PROP_VALUE}
 * for orphan value text.
 *
 * php-src: ext/dom/node.c syncAttributeTreeLinks / ensureAttrValueTextChild
 */
final class JitDomAttrChildEdgeFetch
{
    private const CLASS_ATTR = 'DOMAttr';

    private const PROP_VALUE = 'value';

    public static function fetch(
        Object_ $objectType,
        Value $obj,
        string $propName,
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $context = $objectType->jitContext();
        $propLc = strtolower($propName);
        if ('firstchild' === $propLc || 'lastchild' === $propLc) {
            return self::valueFromPtr(
                $context,
                self::fetchValueTextChildPtr($context, $objectType, $obj, $receiverVar)
            );
        }
        if ('nextsibling' === $propLc || 'previoussibling' === $propLc) {
            return self::valueFromPtr(
                $context,
                self::fetchAttrSiblingPtr($context, $receiverVar, $propLc)
            );
        }

        return self::valueFromPtr($context, self::nullPtr($context));
    }

    public static function hasValueTextChild(
        Context $context,
        Object_ $objectType,
        Value $obj,
        ?JITVariable $receiverVar = null
    ): Value {
        $local = $receiverVar?->compileTimeDomAttrLocalName ?? null;
        $ns = $receiverVar?->compileTimeDomAttrNamespace ?? '';
        if (null !== $local) {
            $cached = self::compileTimeAttrValue($ns, $local);
            if (null !== $cached && '' !== $cached) {
                $i1 = $context->getTypeFromString('int1');

                return $i1->constInt(1, false);
            }
        }
        $valueStr = self::readAttrValueString($context, $objectType, $obj);
        $len = self::stringLength($context, $valueStr);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
    }

    public static function compileTimeAttrValuePublic(string $ns, string $local): ?string
    {
        return self::compileTimeAttrValue($ns, $local);
    }

    private static function compileTimeAttrValue(string $ns, string $local): ?string
    {
        $cached = DomUserScriptAttributeCacheLlvm::literalValue($ns, $local);
        if (null !== $cached) {
            return $cached;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return null;
        }
        foreach (DomParseSimpleXmlJitHelper::rootAttributesArgv($xml) as $pair) {
            if ($pair['namespace'] !== $ns) {
                continue;
            }
            $pos = strpos($pair['qname'], ':');
            $attrLocal = false === $pos ? $pair['qname'] : substr($pair['qname'], $pos + 1);
            if ($attrLocal === $local) {
                return $pair['value'];
            }
        }

        return null;
    }

    public static function fetchAttrChildNodes(
        Object_ $objectType,
        Value $obj,
        ?JITVariable $receiverVar = null
    ): JITVariable {
        $context = $objectType->jitContext();
        $local = $receiverVar?->compileTimeDomAttrLocalName ?? null;
        $ns = $receiverVar?->compileTimeDomAttrNamespace ?? '';
        if (null !== $local) {
            $valueLit = self::compileTimeAttrValue($ns, $local);
            if (null !== $valueLit && '' !== $valueLit) {
                $text = JitDomCreateTextNode::materialize($context, $valueLit);

                return self::boxNodeListVariable(
                    $context,
                    JitDomDocumentElement::buildChildNodesList($context, $obj, 1, $text, null),
                    1,
                    $receiverVar
                );
            }
        }

        $hasChild = self::hasValueTextChild($context, $objectType, $obj, $receiverVar);
        $bbEmpty = BasicBlockHelper::append($context, 'dom_attr_cn_empty');
        $bbOne = BasicBlockHelper::append($context, 'dom_attr_cn_one');
        $bbDone = BasicBlockHelper::append($context, 'dom_attr_cn_done');
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->branchIf($hasChild, $bbOne, $bbEmpty);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(
            JitValueBox::valuePtrFromVariable(
                $context,
                self::boxNodeListVariable(
                    $context,
                    JitDomDocumentElement::buildChildNodesList($context, $obj, 0, null, null),
                    0,
                    $receiverVar
                )
            ),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOne);
        $textPtr = self::fetchValueTextChildPtr($context, $objectType, $obj, $receiverVar);
        $textObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $textPtr)
        );
        $context->builder->store(
            JitValueBox::valuePtrFromVariable(
                $context,
                self::boxNodeListVariable(
                    $context,
                    JitDomDocumentElement::buildChildNodesList($context, $obj, 1, $textObj, null),
                    1,
                    $receiverVar
                )
            ),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $result = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $context->builder->load($resultSlot))
        );
        $result->classUserType = 'DOMNodeList';
        // Runtime orphan/empty-vs-text branch — length resolved via live value probe (#20501).
        if (null !== $local) {
            $valueLit = self::compileTimeAttrValue($ns, $local);
            if (null !== $valueLit) {
                $result->compileTimeDomNodeListLength = '' !== $valueLit ? 1 : 0;
            }
        }

        return $result;
    }

    private static function boxNodeListVariable(
        Context $context,
        JITVariable $list,
        int $length,
        ?JITVariable $ownerVar = null
    ): JITVariable {
        $list->classUserType = 'DOMNodeList';
        $list->compileTimeDomNodeListLength = $length;
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $list->value
        );
        $boxed = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
        $boxed->classUserType = 'DOMNodeList';
        $boxed->compileTimeDomNodeListLength = $length;
        if (null !== ($ownerVar?->compileTimeDomAttrLocalName ?? null)) {
            $boxed->compileTimeDomAttrLocalName = $ownerVar->compileTimeDomAttrLocalName;
            $boxed->compileTimeDomAttrNamespace = $ownerVar->compileTimeDomAttrNamespace ?? '';
        }

        return $boxed;
    }

    private static function fetchValueTextChildPtr(
        Context $context,
        Object_ $objectType,
        Value $obj,
        ?JITVariable $receiverVar
    ): Value {
        $local = $receiverVar?->compileTimeDomAttrLocalName ?? null;
        $ns = $receiverVar?->compileTimeDomAttrNamespace ?? '';
        if (null !== $local) {
            $cached = self::compileTimeAttrValue($ns, $local);
            if (null !== $cached && '' !== $cached) {
                return self::boxObjectPtr(
                    $context,
                    JitDomCreateTextNode::materialize($context, $cached)
                );
            }
        }

        return self::fetchValueTextChildFromLiveValue($context, $objectType, $obj);
    }

    private static function fetchValueTextChildFromLiveValue(
        Context $context,
        Object_ $objectType,
        Value $obj
    ): Value {
        $valueStr = self::readAttrValueString($context, $objectType, $obj);
        $len = self::stringLength($context, $valueStr);
        $i64 = $context->getTypeFromString('int64');
        $hasVal = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $valueVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $valueStr);
        $textObj = JitDomCreateTextNode::fromStringArg($context, $valueVar);
        $resultTy = $context->getTypeFromString('__value__*');

        return $context->builder->select(
            $hasVal,
            self::boxObjectPtr($context, $textObj),
            self::nullPtr($context)
        );
    }

    private static function fetchAttrSiblingPtr(
        Context $context,
        ?JITVariable $receiverVar,
        string $propLc
    ): Value {
        $local = $receiverVar?->compileTimeDomAttrLocalName ?? null;
        $ns = $receiverVar?->compileTimeDomAttrNamespace ?? '';
        if (null === $local || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return self::nullPtr($context);
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml) {
            return self::nullPtr($context);
        }
        $attrs = DomParseSimpleXmlJitHelper::rootAttributesArgv($xml);
        if ([] === $attrs) {
            return self::nullPtr($context);
        }
        $index = null;
        foreach ($attrs as $i => $pair) {
            $pos = strpos($pair['qname'], ':');
            $attrLocal = false === $pos ? $pair['qname'] : substr($pair['qname'], $pos + 1);
            if ($pair['namespace'] === $ns && $attrLocal === $local) {
                $index = $i;
                break;
            }
        }
        if (null === $index) {
            return self::nullPtr($context);
        }
        $delta = 'nextsibling' === $propLc ? 1 : -1;
        $siblingIndex = $index + $delta;
        if ($siblingIndex < 0 || $siblingIndex >= \count($attrs)) {
            return self::nullPtr($context);
        }
        $pair = $attrs[$siblingIndex];
        $pos = strpos($pair['qname'], ':');
        $siblingLocal = false === $pos ? $pair['qname'] : substr($pair['qname'], $pos + 1);
        $siblingObj = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, $pair['namespace'], $siblingLocal);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $siblingObj,
            $objPtrTy->constNull()
        );
        $resultTy = $context->getTypeFromString('__value__*');

        return $context->builder->select(
            $isNull,
            self::nullPtr($context),
            self::boxObjectPtr($context, $siblingObj)
        );
    }

    public static function stringLengthPublic(Context $context, Value $strPtr): Value
    {
        return self::stringLength($context, $strPtr);
    }

    public static function readAttrValueStringForOwner(
        Context $context,
        Object_ $objectType,
        Value $ownerObj
    ): Value {
        return self::readAttrValueString($context, $objectType, $ownerObj);
    }

    private static function stringLength(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
    }

    private static function readAttrValueString(Context $context, Object_ $objectType, Value $obj): Value
    {
        JitDomAttributeNodeNS::ensureAttrValueLayoutForGetAttribute($context);
        $className = JitDomAttributeNodeNS::attrClassForUserScriptCache();
        $classId = $objectType->lookup($className);
        if (!$objectType->hasProperty($classId, self::PROP_VALUE)) {
            $objectType->defineProperty($classId, self::PROP_VALUE, JITVariable::TYPE_STRING);
        }
        $valueVar = $objectType->propertyFetch($obj, $className, self::PROP_VALUE);
        if (JITVariable::TYPE_STRING === $valueVar->type) {
            return $valueVar->value;
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::normalizeValuePtr($context, JitValueBox::valuePtrFromVariable($context, $valueVar))
        );
    }

    private static function valueFromPtr(Context $context, Value $ptr): JITVariable
    {
        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }

    private static function boxObjectPtr(Context $context, Value $object): Value
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

    private static function nullPtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
