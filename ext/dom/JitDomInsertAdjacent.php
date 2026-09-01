<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInsertAdjacentRuntime;
use PHPCompiler\JIT\Call\DomNodeAppendChild;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMElement::insertAdjacentElement/Text.
 *
 * php-src: ext/dom/php_dom.c php_dom_insert_adjacent
 *          ext/dom/element.c PHP_METHOD(DOMElement, insertAdjacentText)
 *
 * Thin standalone AOT materializes createElement nodes without DomRegistry
 * ({@see JitDomInsertBefore}) — the VM bridge leaves firstChild/parentNode stale.
 * Use LiveSlots (appendChild / insertBefore / ChildNode sibling insert) instead.
 *
 * Null $element is legal (?DOMElement) and returns null — variable null is
 * TYPE_VALUE without isNullConstant; readObject on that box SIGSEGVs (#33763,
 * peer #33031 / #33716).
 */
final class JitDomInsertAdjacent
{
    public static function invokeElement(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_adjacent_element_cont');
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::insertAdjacentElement() expects receiver, where, element');
        }

        $where = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $where) {
            throw new \LogicException(
                'DOMElement::insertAdjacentElement() user-script AOT requires a compile-time $where'
            );
        }
        if (!\in_array(strtolower($where), ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) {
            ExceptionBridge::emitValueErrorAndAbort(
                $context,
                'DOMElement::insertAdjacentElement(): Argument #1 ($where) must be a valid adjacency insertion position'
            );

            return self::boxNull($context);
        }

        $nodeArg = $args[2];
        if (self::isCompileTimeNull($nodeArg)) {
            return self::boxNull($context);
        }
        // Variable null ($n = null): TYPE_VALUE, no isNullConstant (#33763).
        if (JITVariable::TYPE_VALUE === $nodeArg->type) {
            return self::invokeElementMaybeNullNode($context, $args[0], $where, $nodeArg);
        }

        return self::invokeElementWithObjectNode($context, $args[0], $where, $nodeArg);
    }

    public static function invokeText(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_adjacent_text_cont');
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::insertAdjacentText() expects receiver, where, data');
        }

        $where = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $data = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $where || null === $data) {
            throw new \LogicException(
                'DOMElement::insertAdjacentText() user-script AOT requires compile-time $where and $data'
            );
        }
        if (!\in_array(strtolower($where), ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) {
            ExceptionBridge::emitValueErrorAndAbort(
                $context,
                'DOMElement::insertAdjacentText(): Argument #1 ($where) must be a valid adjacency insertion position'
            );

            return self::boxNull($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $textObj = JitDomCreateTextNode::materialize($context, $data);
            $textVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $textObj);
            self::invokeInsertAdjacentLiveSlots($context, $args[0], strtolower($where), $textVar);

            return self::boxNull($context);
        }

        DomInsertAdjacentRuntime::ensureLinked($context);
        $element = self::loadObjectArg($context, $args[0], 'DOMElement::insertAdjacentText()');
        $whereStr = $context->builder->load($context->constantStringFromString($where));
        $dataStr = $context->builder->load($context->constantStringFromString($data));
        $context->builder->call(
            $context->lookupFunction(DomInsertAdjacentRuntime::ABI_TEXT),
            $element,
            $whereStr,
            $dataStr
        );

        return self::boxNull($context);
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /**
     * Runtime null check for boxed TYPE_VALUE before __value__readObject (#33763).
     * php-src: Z_PARAM_OBJ_OF_CLASS_OR_NULL — null → return null.
     */
    private static function invokeElementMaybeNullNode(
        Context $context,
        JITVariable $receiver,
        string $where,
        JITVariable $nodeArg
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_maybe_null');
        $nodePtr = JitValueBox::valuePtrFromVariable($context, $nodeArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($nodePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );

        $nullBlock = BasicBlockHelper::append($context, 'dom_iae_node_null');
        $objBlock = BasicBlockHelper::append($context, 'dom_iae_node_obj');
        $doneBlock = BasicBlockHelper::append($context, 'dom_iae_node_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::boxNull($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_node_null_ret');
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $objResult = self::invokeElementWithObjectNode($context, $receiver, $where, $nodeArg);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_node_obj_ret');
        $objPred = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__value__*'));
        $phi->addIncoming($nullResult, $nullPred);
        $phi->addIncoming($objResult, $objPred);

        return $phi;
    }

    private static function invokeElementWithObjectNode(
        Context $context,
        JITVariable $receiver,
        string $where,
        JITVariable $nodeArg
    ): Value {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // Pin object identity before LiveSlots mutate slots (#27480).
            $childObj = self::loadObjectArg($context, $nodeArg, 'DOMElement::insertAdjacentElement()');
            self::invokeInsertAdjacentLiveSlots($context, $receiver, strtolower($where), $nodeArg);

            return self::boxObject($context, $childObj);
        }

        DomInsertAdjacentRuntime::ensureLinked($context);
        $element = self::loadObjectArg($context, $receiver, 'DOMElement::insertAdjacentElement()');
        $node = self::loadObjectArg($context, $nodeArg, 'DOMElement::insertAdjacentElement()');
        $whereStr = $context->builder->load($context->constantStringFromString($where));
        $context->builder->call(
            $context->lookupFunction(DomInsertAdjacentRuntime::ABI_ELEMENT),
            $element,
            $whereStr,
            $node
        );

        return self::boxObject($context, $node);
    }

    /**
     * LiveSlots insertAdjacent* — peer {@see JitDomInsertBefore} / {@see JitDomChildNodeSiblingInsert}.
     */
    private static function invokeInsertAdjacentLiveSlots(
        Context $context,
        JITVariable $receiver,
        string $where,
        JITVariable $newChildVar
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_live_'.$where);
        match ($where) {
            'beforebegin' => self::liveBeforeBegin($context, $receiver, $newChildVar),
            'afterbegin' => self::liveAfterBegin($context, $receiver, $newChildVar),
            'beforeend' => (new DomNodeAppendChild())->call($context, $receiver, $newChildVar),
            'afterend' => self::liveAfterEnd($context, $receiver, $newChildVar),
            default => throw new \LogicException('invalid insertAdjacent position: '.$where),
        };
    }

    private static function liveBeforeBegin(
        Context $context,
        JITVariable $elementVar,
        JITVariable $newChildVar
    ): void {
        $parentVar = self::requireParentVar($context, $elementVar);
        JitDomChildNodeSiblingInsert::invokeBefore($context, $parentVar, $newChildVar, $elementVar);
    }

    private static function liveAfterEnd(
        Context $context,
        JITVariable $elementVar,
        JITVariable $newChildVar
    ): void {
        $parentVar = self::requireParentVar($context, $elementVar);
        JitDomChildNodeSiblingInsert::invokeAfter($context, $parentVar, $newChildVar, $elementVar);
    }

    private static function liveAfterBegin(
        Context $context,
        JITVariable $elementVar,
        JITVariable $newChildVar
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_afterbegin');
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $element = JitDomParentChildLinkLayout::loadObjectArg($context, $elementVar);
        $first = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $element,
            VmDom::PROP_FIRST_CHILD,
            'dom_iae_afterbegin_fc'
        );
        $objPtrTy = $context->getTypeFromString('__object__*');
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $first, $objPtrTy->constNull());
        $bbAppend = BasicBlockHelper::append($context, 'dom_iae_afterbegin_append');
        $bbInsert = BasicBlockHelper::append($context, 'dom_iae_afterbegin_insert');
        $bbDone = BasicBlockHelper::append($context, 'dom_iae_afterbegin_done');
        $context->builder->branchIf($firstNull, $bbAppend, $bbInsert);

        $context->builder->positionAtEnd($bbAppend);
        (new DomNodeAppendChild())->call($context, $elementVar, $newChildVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbInsert);
        $firstJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $first);
        JitDomInsertBefore::syncUserScriptInsertBeforeSlotsPublic(
            $context,
            $elementVar,
            $newChildVar,
            $firstJit
        );
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /** @return JITVariable parent JITVariable or abort with DOMException */
    private static function requireParentVar(Context $context, JITVariable $elementVar): JITVariable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_iae_req_parent');
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $element = JitDomParentChildLinkLayout::loadObjectArg($context, $elementVar);
        $parent = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $element,
            VmDom::PROP_PARENT_NODE,
            'dom_iae_req_parent_load'
        );
        $objPtrTy = $context->getTypeFromString('__object__*');
        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parent, $objPtrTy->constNull());
        $bbThrow = BasicBlockHelper::append($context, 'dom_iae_req_parent_throw');
        $bbOk = BasicBlockHelper::append($context, 'dom_iae_req_parent_ok');
        $context->builder->branchIf($parentNull, $bbThrow, $bbOk);

        $context->builder->positionAtEnd($bbThrow);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Hierarchy request error'
        );

        $context->builder->positionAtEnd($bbOk);

        return new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg, string $label): Value
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

        throw new \LogicException($label.' expects object nodes');
    }

    private static function boxObject(Context $context, Value $object): Value
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

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
