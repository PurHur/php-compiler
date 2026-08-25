<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPCompiler\ext\dom\JitDomAppendChildLiveSlots;
use PHPCompiler\ext\dom\JitDomChildNodeSiblingInsert;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomCreateTextNode;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\dom\JitDomGetNodePath;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\ext\dom\JitDomNodeChildProperty;
use PHPCompiler\ext\dom\JitDomParentChildLinkLayout;
use PHPCompiler\ext\dom\JitDomRemoveChild;
use PHPCompiler\ext\dom\JitDomReplaceChild;
use PHPCompiler\ext\dom\JitDomRequireDomNodeArg;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DOM ChildNode::{after,before,replaceWith,remove} (#26752).
 *
 * User-script AOT updates the parent's {@see VmDom::PROP_USER_SCRIPT_INNER_XML}
 * (same saveXML path as ParentNode append, #26757 / #26765).
 */
final class DomNodeChildNodeMutationRuntime
{
    public static function invokeAfter(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeSiblingMutation($context, 'after', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeBefore(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeSiblingMutation($context, 'before', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeReplaceWith(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        // php-src: replaceWith() with no nodes ≡ remove (dom_child_replace_with empty fragment).
        if (0 === $extraArgCount) {
            return self::invokeRemove($context, $receiver);
        }

        return self::invokeSiblingMutation($context, 'replacewith', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeRemove(Context $context, Variable $receiver): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnode_remove');
            $parent = self::loadParentObject($context, $receiver);
            $parentVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $parent);
            // ChildNode::remove ≡ parent->removeChild($this): LiveSlots unlink +
            // in-place childNodes length so held lists stay live (#32823 / re-#32774).
            // Prior path allocated a fresh length-0 list and blanked InnerXml.
            JitDomRemoveChild::invoke($context, $parentVar, $receiver);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnode_remove_post');

            return self::nullValuePtr($context);
        }

        return DomInstanceMethodRuntime::invoke($context, 0, 'remove', $receiver);
    }

    private static function invokeSiblingMutation(
        Context $context,
        string $kind,
        int $extraArgCount,
        Variable $receiver,
        Variable ...$extraArgs
    ): Value {
        if ($extraArgCount !== \count($extraArgs)) {
            throw new \LogicException('DomNodeChildNodeMutationRuntime arity mismatch');
        }
        if ($extraArgCount < 1 || $extraArgCount > DomNodeLiveMutationRuntime::MAX_EXTRA_ARGS) {
            throw new \LogicException('DomNodeChildNodeMutationRuntime unsupported arity');
        }

        // php-src ChildNode nodes: DOMNode|string — null must TypeError before LiveSlots (#33746 / peer #33741).
        $method = match ($kind) {
            'before' => 'DOMElement::before',
            'after' => 'DOMElement::after',
            default => 'DOMElement::replaceWith',
        };
        foreach ($extraArgs as $i => $arg) {
            if (JitDomRequireDomNodeArg::guardDomNodeOrStringOrAbort(
                $context,
                $arg,
                $method,
                $i + 1
            )) {
                return self::nullValuePtr($context);
            }
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnode_'.$kind);
            // Document-wide saveXML must not replay the loadXML literal after a
            // sibling insert that adds comments/PIs around the root (#34160).
            JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
            $parent = self::loadParentObject($context, $receiver);
            if ('before' === $kind || 'after' === $kind) {
                // InnerXml splice is best-effort for saveXML (#26752). It must not
                // short-circuit LiveSlots — otherwise held `$parent->childNodes`
                // stays stale and item(N) SIGSEGVs (#32817 / peer #32801).
                // Keep original $extraArgs here so string literals still splice markup;
                // LiveSlots below needs objects (#34760).
                self::trySyncSiblingInsertInnerXml($context, $kind, $receiver, $parent, $extraArgs);
                $parentVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $parent);
                // Multi-arg: LiveSlots each node (peer ParentNode append #32838).
                // before(...$nodes): each inserts before the original receiver → b,c,a.
                // after(...$nodes): advance anchor to the last inserted → a,b,c (#32848).
                // php-src childnode.c: string arms become text nodes before link (#34760).
                $afterAnchor = $receiver;
                foreach ($extraArgs as $newChildVar) {
                    $nodeVar = self::coerceNodeOrStringArg($context, $newChildVar);
                    if ('before' === $kind) {
                        JitDomChildNodeSiblingInsert::invokeBefore(
                            $context,
                            $parentVar,
                            $nodeVar,
                            $receiver
                        );
                    } else {
                        JitDomChildNodeSiblingInsert::invokeAfter(
                            $context,
                            $parentVar,
                            $nodeVar,
                            $afterAnchor
                        );
                        $afterAnchor = $nodeVar;
                    }
                }
            } else {
                // replaceWith: LiveSlots + InnerXml sibling-preserving rewrite (#32822 /
                // peer replaceChild #32784). storeInnerXmlFromArgs alone wiped siblings
                // and left held childNodes pins on the old node.
                // Multi-arg: ReplaceChild for arg0, then after() for arg1..N — peer
                // after/before multi LiveSlots (#32848 / #32887).
                // Identity-only `$n->replaceWith($n)`: php-src fragment unlink+reinsert
                // is a no-op; skip LiveSlots so sibling walks do not hang (#34804).
                $parentVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $parent);
                $firstNode = self::coerceNodeOrStringArg($context, $extraArgs[0]);
                if (1 === $extraArgCount && Variable::TYPE_OBJECT === $firstNode->type) {
                    BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cn_replacewith_maybe_id');
                    $recvObj = self::receiverObject($context, $receiver);
                    $firstObj = $context->helper->loadValue($firstNode);
                    $isSame = $context->builder->icmp(Builder::INT_EQ, $recvObj, $firstObj);
                    $bbIdentity = BasicBlockHelper::append($context, 'dom_cn_replacewith_identity');
                    $bbMutate = BasicBlockHelper::append($context, 'dom_cn_replacewith_mutate');
                    $bbDone = BasicBlockHelper::append($context, 'dom_cn_replacewith_done');
                    $context->builder->branchIf($isSame, $bbIdentity, $bbMutate);
                    $context->builder->positionAtEnd($bbIdentity);
                    $context->builder->branch($bbDone);
                    $context->builder->positionAtEnd($bbMutate);
                    self::emitReplaceWithLiveSlots(
                        $context,
                        $parent,
                        $parentVar,
                        $receiver,
                        $firstNode,
                        $extraArgs
                    );
                    $context->builder->branch($bbDone);
                    $context->builder->positionAtEnd($bbDone);
                } else {
                    self::emitReplaceWithLiveSlots(
                        $context,
                        $parent,
                        $parentVar,
                        $receiver,
                        $firstNode,
                        $extraArgs
                    );
                }
            }

            return self::nullValuePtr($context);
        }

        return DomInstanceMethodRuntime::invoke($context, $extraArgCount, $kind, $receiver, ...$extraArgs);
    }

    /**
     * LiveSlots ReplaceChild(arg0) + after(arg1..N) + InnerXml (#32822 / #32887 / #34804).
     *
     * @param list<Variable> $extraArgs
     */
    private static function emitReplaceWithLiveSlots(
        Context $context,
        Value $parent,
        Variable $parentVar,
        Variable $receiver,
        Variable $firstNode,
        array $extraArgs
    ): void {
        JitDomReplaceChild::syncUserScriptReplaceSlotsPublic(
            $context,
            $parentVar,
            $firstNode,
            $receiver
        );
        $afterAnchor = $firstNode;
        $tail = \array_slice($extraArgs, 1);
        foreach ($tail as $newChildVar) {
            $nodeVar = self::coerceNodeOrStringArg($context, $newChildVar);
            JitDomChildNodeSiblingInsert::invokeAfter(
                $context,
                $parentVar,
                $nodeVar,
                $afterAnchor
            );
            $afterAnchor = $nodeVar;
        }
        // Compile-time chunk splice duplicates nodes that LiveSlots moved (#34804
        // self_a / a_self). Rebuild InnerXml from the LiveSlots child chain instead.
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject(
            $context,
            $parent,
            'dom_cn_rw_rebuild_doc'
        );
        $bbSkip = BasicBlockHelper::append($context, 'dom_cn_rw_skip_rebuild');
        $bbRebuild = BasicBlockHelper::append($context, 'dom_cn_rw_rebuild');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_rw_rebuild_done');
        $context->builder->branchIf($isDoc, $bbSkip, $bbRebuild);
        $context->builder->positionAtEnd($bbRebuild);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren(
            $context,
            $parent
        );
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward(
            $context,
            $parent
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** @param list<Variable> $extraArgs */
    private static function storeInnerXmlFromArgs(Context $context, Value $parent, array $extraArgs): void
    {
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return;
                }
                $pieces[] = $lit;
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return;
            }
            $pieces[] = '<'.$tag.'/>';
        }
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, implode('', $pieces));
    }

    /**
     * Splice ChildNode::before/after args into parent PROP_USER_SCRIPT_INNER_XML (#26752).
     *
     * @param list<Variable> $extraArgs
     */
    private static function trySyncSiblingInsertInnerXml(
        Context $context,
        string $kind,
        Variable $receiver,
        Value $parent,
        array $extraArgs
    ): bool {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return false;
        }
        $markup = self::compileTimeMarkupFromArgs($extraArgs);
        if (null === $markup || '' === $markup) {
            return false;
        }
        $parentInner = self::compileTimeParentInnerXml();
        if (null === $parentInner) {
            return false;
        }
        $index = self::resolveReceiverChunkIndex($receiver, $parentInner);
        if (null === $index) {
            return false;
        }
        $after = 'after' === $kind;
        $inner = DomParseSimpleXmlJitHelper::innerXmlInsertMarkupAt($parentInner, $index, $markup, $after);
        if (null === $inner) {
            return false;
        }
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);

        return true;
    }

    /**
     * Replace the receiver's direct-child chunk with all replaceWith args (#32887).
     *
     * Peer {@see trySyncSiblingInsertInnerXml}: multi-arg markup must replace the
     * old node in place (not wipe the parent to only the new tags).
     *
     * @param list<Variable> $extraArgs
     */
    private static function trySyncReplaceWithInnerXml(
        Context $context,
        Variable $receiver,
        Value $parent,
        array $extraArgs
    ): bool {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return false;
        }
        $markup = self::compileTimeMarkupFromArgs($extraArgs);
        if (null === $markup || '' === $markup) {
            return false;
        }
        $parentInner = self::compileTimeParentInnerXml();
        if (null === $parentInner) {
            return false;
        }
        $index = self::resolveReceiverChunkIndex($receiver, $parentInner);
        if (null === $index) {
            return false;
        }
        $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks($parentInner);
        if ($index < 0 || $index >= \count($chunks)) {
            return false;
        }
        $chunks[$index] = $markup;
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, implode('', $chunks));

        return true;
    }

    private static function compileTimeParentInnerXml(): ?string
    {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        if (null !== JitDomGetNodePath::$lastParentInner) {
            return JitDomGetNodePath::$lastParentInner;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return null;
        }

        return DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
    }

    private static function resolveReceiverChunkIndex(Variable $receiver, string $parentInner): ?int
    {
        if (null !== $receiver->compileTimeDomChildIndex) {
            return $receiver->compileTimeDomChildIndex;
        }
        $tag = $receiver->compileTimeDomTagName ?? JitDomNodeChildProperty::$lastFetchedTagName;
        if (null !== JitDomNodeChildProperty::$lastFetchedChildIndex && null !== $tag) {
            return JitDomNodeChildProperty::$lastFetchedChildIndex;
        }
        if (null === $tag || '' === $tag) {
            return null;
        }
        $tagLc = strtolower($tag);
        foreach (DomParseSimpleXmlJitHelper::directChildMarkupChunks($parentInner) as $i => $chunk) {
            $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunk);
            if (null !== $parsed && strtolower($parsed['tag']) === $tagLc) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<Variable> $extraArgs
     */
    private static function compileTimeMarkupFromArgs(array $extraArgs): ?string
    {
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return null;
                }
                $pieces[] = $lit;
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return null;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return null;
            }
            $pieces[] = '<'.$tag.'/>';
        }

        return implode('', $pieces);
    }

    /**
     * php-src ChildNode string arms → text nodes before LiveSlots link (#34760).
     *
     * TYPE_STRING / boxed string must not reach {@see JitDomParentChildLinkLayout::loadObjectArg}.
     */
    private static function coerceNodeOrStringArg(Context $context, Variable $arg): Variable
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $arg;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            $obj = JitDomCreateTextNode::fromStringArg($context, $arg);

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $obj = self::runtimeValueToNodeObject($context, $arg);

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        return $arg;
    }

    /**
     * Runtime DOMNode|string value box → object (text stand-in or node).
     *
     * Uses a stack slot instead of a PHI so helpers that split the insert block
     * (string materialize) cannot leave mismatched PHI predecessors (#34760).
     */
    private static function runtimeValueToNodeObject(Context $context, Variable $arg): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cn_val_to_node');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $bbStr = BasicBlockHelper::append($context, 'dom_cn_val_str');
        $bbObj = BasicBlockHelper::append($context, 'dom_cn_val_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_cn_val_merge');
        $context->builder->branchIf($isString, $bbStr, $bbObj);

        $context->builder->positionAtEnd($bbStr);
        $textObj = JitDomCreateTextNode::fromStringArg($context, $arg);
        // fromStringArg may leave the insert block past $bbStr — store/branch there.
        $context->builder->store($textObj, $slot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbObj);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->store($obj, $slot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);

        return $context->builder->load($slot);
    }

    private static function loadParentObject(Context $context, Variable $receiver): Value
    {
        $receiverObj = self::receiverObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $slot = $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($slot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_cn_parent_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_cn_parent_read');
        $merge = BasicBlockHelper::append($context, 'dom_cn_parent_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $parentObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($parentObj, $readBlock);

        return $phi;
    }

    private static function receiverObject(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOM ChildNode receiver must be an object');
    }

    private static function nullValuePtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
