<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * get_class() for thin-AOT DOM character-data stand-ins (#33607 / re-#20501).
 *
 * createTextNode / Attr value text children allocate as DOMElement with nodeType
 * TEXT|COMMENT|… — php-src reports DOMText, not DOMElement (ext/dom/node.c).
 */
final class JitDomStandinGetClass
{
    private const CLASS_ELEMENT = 'DOMElement';

    public static function emitClassName(Context $context, JITVariable $arg): Value
    {
        $obj = self::loadObject($context, $arg);
        $objectType = $context->type->object;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $i64 = $context->getTypeFromString('int64');
        $isElement = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($elementClassId, false)
        );
        $defaultName = GetClassRuntime::emitClassNameFromId($context, $classId);
        $bbStand = BasicBlockHelper::append($context, 'dom_gc_stand_check');
        $bbMerge = BasicBlockHelper::append($context, 'dom_gc_stand_merge');
        $nonElementPred = $context->builder->getInsertBlock();
        $context->builder->branchIf($isElement, $bbStand, $bbMerge);

        $context->builder->positionAtEnd($bbStand);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NODE_TYPE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NODE_TYPE, JITVariable::TYPE_NATIVE_LONG);
        }
        $nodeTypeVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            VmDom::PROP_NODE_TYPE,
            $elementClassId
        );
        $nodeType = $context->helper->loadValue($nodeTypeVar);
        $standName = $defaultName;
        foreach (self::standinClassNamesByNodeType() as $type => $className) {
            $isType = $context->builder->icmp(
                Builder::INT_EQ,
                $nodeType,
                $i64->constInt($type, false)
            );
            $label = $context->builder->load($context->constantStringFromString($className));
            $standName = $context->builder->select($isType, $label, $standName);
        }
        $standPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($defaultName, $nonElementPred);
        $phi->addIncoming($standName, $standPred);

        return $phi;
    }

    /**
     * instanceof for character-data stand-ins allocated as DOMElement (#33607 peer).
     *
     * @return null when normal class_id instanceof should run
     */
    public static function tryEmitInstanceOf(
        Context $context,
        JITVariable $expr,
        string $className
    ): ?JITVariable {
        $wantType = self::nodeTypeForStandinClass($className);
        if (null === $wantType) {
            return null;
        }

        if (JITVariable::TYPE_OBJECT === $expr->type) {
            $obj = $context->helper->loadValue($expr);
        } elseif (JITVariable::TYPE_VALUE === $expr->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $expr)
            );
        } else {
            return null;
        }
        $objectType = $context->type->object;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $elementClassId = $objectType->lookup(self::CLASS_ELEMENT);
        $i64 = $context->getTypeFromString('int64');
        $isElement = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i64->constInt($elementClassId, false)
        );
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $bbStand = BasicBlockHelper::append($context, 'dom_io_stand_check');
        $bbMerge = BasicBlockHelper::append($context, 'dom_io_stand_merge');
        $nonElementPred = $context->builder->getInsertBlock();
        $context->builder->branchIf($isElement, $bbStand, $bbMerge);

        $context->builder->positionAtEnd($bbStand);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NODE_TYPE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NODE_TYPE, JITVariable::TYPE_NATIVE_LONG);
        }
        $nodeTypeVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            VmDom::PROP_NODE_TYPE,
            $elementClassId
        );
        $nodeType = $context->helper->loadValue($nodeTypeVar);
        $match = $context->builder->icmp(
            Builder::INT_EQ,
            $nodeType,
            $i64->constInt($wantType, false)
        );
        $standPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $nonElementPred);
        $phi->addIncoming($match, $standPred);

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $phi
        );
    }

    private static function nodeTypeForStandinClass(string $className): ?int
    {
        $lc = strtolower(ltrim($className, '\\'));
        foreach (self::standinClassNamesByNodeType() as $type => $name) {
            if (strtolower($name) === $lc) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function standinClassNamesByNodeType(): array
    {
        return [
            DomConstants::XML_TEXT_NODE => 'DOMText',
            DomConstants::XML_COMMENT_NODE => 'DOMComment',
            DomConstants::XML_CDATA_SECTION_NODE => 'DOMCdataSection',
            DomConstants::XML_PROCESSING_INSTRUCTION_NODE => 'DOMProcessingInstruction',
        ];
    }

    private static function loadObject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
