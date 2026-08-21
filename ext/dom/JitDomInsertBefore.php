<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Call\DomNodeAppendChild;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::insertBefore() (#22686, #26458, #27449, #32801, #33031).
 *
 * Thin standalone AOT materializes createElement nodes without DomRegistry
 * ({@see JitDomCreateElement::materializeElementFromLiteral}). The NestedJIT
 * DomRegistry bridge then leaves LLVM childNodes/firstChild/parentNode stale —
 * mirror ParentNode::append / replaceChild slot sync (php-src ext/dom/node.c).
 * Live held childNodes: {@see JitDomInsertBeforeLiveSlots}.
 *
 * Null / omitted refChild ≡ append (php-src). Must use {@see DomNodeAppendChild}
 * (LiveSlots), not historic {@see JitDomAppendChild::invoke} stub (#33031 / re-#26458).
 */
final class JitDomInsertBefore
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::insertBefore() expects receiver and newChild');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_before_cont');

        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::insertBefore', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        // php-src: null / omitted refChild ≡ append (ext/dom/node.c).
        if (\count($args) < 3 || self::isCompileTimeNullRef($args[2])) {
            return self::appendAsNullRef($context, $args[0], $args[1]);
        }

        // Variable null ($ref = null) is TYPE_VALUE without isNullConstant — branch
        // before readObject (literal null already handled above) (#33031).
        if (JITVariable::TYPE_VALUE === $args[2]->type) {
            return self::invokeWithMaybeNullRef($context, $args[0], $args[1], $args[2]);
        }

        return self::invokeWithObjectRef($context, $args[0], $args[1], $args[2]);
    }

    public static function syncUserScriptInsertBeforeSlotsPublic(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): void {
        self::syncUserScriptInsertBeforeSlots($context, $parentVar, $newChildVar, $refChildVar);
    }

    /**
     * In-place +1 on held childNodes (ChildNode::after append-tail; #32801).
     *
     * $item0/$item1 kept for call-site compatibility; pins are refreshed from
     * parent.firstChild / first→next inside LiveSlots.
     */
    public static function bumpChildNodesLengthPublic(
        Context $context,
        Value $parent,
        Value $item0,
        Value $item1
    ): void {
        unset($item0, $item1);
        JitDomInsertBeforeLiveSlots::incrementChildNodesLengthInPlace($context, $parent);
    }

    /**
     * Full appendChild path for null-ref insertBefore (#33031).
     *
     * Historic {@see JitDomAppendChild::invoke} only wrote parentNode — LiveSlots /
     * InnerXml stayed stale so the node was dropped from saveXML / childNodes.
     */
    private static function appendAsNullRef(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar
    ): Value {
        return (new DomNodeAppendChild())->call($context, $parentVar, $newChildVar);
    }

    private static function isCompileTimeNullRef(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    private static function invokeWithMaybeNullRef(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_maybe_null');
        $refPtr = JitValueBox::valuePtrFromVariable($context, $refChildVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($refPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );

        $nullBlock = BasicBlockHelper::append($context, 'dom_ib_ref_null');
        $objBlock = BasicBlockHelper::append($context, 'dom_ib_ref_obj');
        $doneBlock = BasicBlockHelper::append($context, 'dom_ib_ref_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::appendAsNullRef($context, $parentVar, $newChildVar);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_ref_null_ret');
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objBlock);
        $objResult = self::invokeWithObjectRef($context, $parentVar, $newChildVar, $refChildVar);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_ref_obj_ret');
        $objPred = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($valuePtrTy);
        $phi->addIncoming($nullResult, $nullPred);
        $phi->addIncoming($objResult, $objPred);

        return $phi;
    }

    private static function invokeWithObjectRef(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): Value {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            self::syncUserScriptInsertBeforeSlots($context, $parentVar, $newChildVar, $refChildVar);
            // LiveSlots refresh held pins (#32801); saveXML still reads INNER_XML (#32940 / peer #32903).
            self::syncUserScriptInnerXml($context, $parentVar, $newChildVar, $refChildVar);
            DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_insert_before_post');

            return self::boxObjectResult($context, self::loadObjectArg($context, $newChildVar));
        }

        DomNodeTreeMutationRuntime::ensureInsertBeforeLinked($context);

        $parent = self::loadObjectArg($context, $parentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $refChild = self::loadObjectArg($context, $refChildVar);
        $context->builder->call(
            $context->lookupFunction(DomNodeTreeMutationRuntime::ABI_INSERT_BEFORE),
            $parent,
            $newChild,
            $refChild
        );

        return self::boxObjectResult($context, $newChild);
    }

    /**
     * Update live tree LLVM slots for thin-AOT insertBefore (#27449, #32801).
     *
     * Skipping DomRegistry NestedJIT: unregistered createElement nodes leave
     * childNodes length / firstChild / parentNode unchanged after the call.
     */
    private static function syncUserScriptInsertBeforeSlots(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): void {
        $parent = self::loadObjectArg($context, $parentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $refChild = self::loadObjectArg($context, $refChildVar);
        // Wrong Document / Hierarchy Request before LiveSlots (#30274).
        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            $parent,
            $newChild
        );
        if (($newChildVar->compileTimeDomTagName ?? null) === JitDomCreateDocumentType::TAG_KIND) {
            DomUserScriptDoctypeLlvm::markAttached();
        }
        JitDomInsertBeforeLiveSlots::sync($context, $parent, $newChild, $refChild);
    }

    /**
     * Splice createElement markup into parent PROP_USER_SCRIPT_INNER_XML (#32940).
     *
     * Peer {@see JitDomReplaceChild::syncUserScriptInnerXml}: item($N) ARG_SEND
     * temps often drop compileTimeDomChildIndex — use lastFetched* fallbacks.
     * Only called from {@see invoke} (not ChildNode::before, which owns its own
     * InnerXml path via DomNodeChildNodeMutationRuntime).
     */
    private static function syncUserScriptInnerXml(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $refChildVar
    ): void {
        $newTag = $newChildVar->compileTimeDomTagName ?? null;
        if (null === $newTag || '' === $newTag) {
            return;
        }
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $xml = $parentVar->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return;
        }
        $newInner = $newChildVar->compileTimeDomInnerXml ?? '';
        $markup = '' === $newInner
            ? '<'.$newTag.'/>'
            : '<'.$newTag.'>'.$newInner.'</'.$newTag.'>';
        $parentInner = DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
        // Already applied this insert into the fold source (invoke can re-enter) (#32972).
        $open = '<'.$newTag;
        if (str_starts_with(ltrim($parentInner), $open)) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $index = $refChildVar->compileTimeDomChildIndex
            ?? JitDomNodeListItem::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? null;
        if (null === $index) {
            $refTag = $refChildVar->compileTimeDomTagName
                ?? JitDomNodeListItem::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$lastFetchedTagName
                ?? null;
            if (null !== $refTag) {
                foreach ($nodes as $i => $node) {
                    if ('element' === $node['kind'] && strtolower($refTag) === $node['data']) {
                        $index = $i;
                        break;
                    }
                }
            }
        }
        if (null === $index) {
            return;
        }
        $inner = DomParseSimpleXmlJitHelper::innerXmlInsertMarkupAt(
            $parentInner,
            $index,
            $markup,
            false
        );
        if (null === $inner) {
            return;
        }
        $parent = self::loadObjectArg($context, $parentVar);
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);
        JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($inner, $parentVar);
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

        throw new \LogicException('DOMNode::insertBefore() expects object nodes');
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
