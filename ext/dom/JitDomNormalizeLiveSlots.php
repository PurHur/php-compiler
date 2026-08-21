<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT live-slot DOMNode::normalize() for createTextNode #text stand-ins (#33438).
 *
 * DomNormalizeJitHelper only merges DomRegistry XML_TEXT_NODE children. User-script
 * AOT allocates text as unregistered DOMElement stand-ins (nodeName "#text"), so the
 * NestedJIT path is a no-op and childNodes->length / parent textContent stay wrong
 * while saveXML INNER_XML still shows the markup.
 *
 * Peer: {@see JitDomAppendChildLiveSlots}, {@see JitDomRemoveChildLiveSlots}.
 * php-src: ext/dom/node.c php_dom_normalize_legacy / xmlTextMerge.
 */
final class JitDomNormalizeLiveSlots
{
    private static int $seq = 0;

    private static function tag(string $prefix): string
    {
        return $prefix.'_'.(string) (++self::$seq);
    }

    public static function sync(Context $context, Value $parent): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_norm_live'));
        self::ensureLayout($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $strTy = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $curAlloca = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $aggAlloca = BasicBlockHelper::entryAlloca($context, $strTy);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($empty, $aggAlloca);

        $first = JitDomParentChildLinkLayout::loadFirstChild($context, $parent, self::tag('dom_norm_first'));
        $context->builder->store($first, $curAlloca);

        $bbLoop = BasicBlockHelper::append($context, self::tag('dom_norm_loop'));
        $bbBody = BasicBlockHelper::append($context, self::tag('dom_norm_body'));
        $bbDone = BasicBlockHelper::append($context, self::tag('dom_norm_done'));
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $isText = self::isTextStandin($context, $cur);
        $bbText = BasicBlockHelper::append($context, self::tag('dom_norm_text'));
        $bbSkip = BasicBlockHelper::append($context, self::tag('dom_norm_skip'));
        $context->builder->branchIf($isText, $bbText, $bbSkip);

        // Non-text: advance to nextSibling (one-level merge; nested elements keep NestedJIT).
        $context->builder->positionAtEnd($bbSkip);
        $skipNext = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $cur,
            VmDom::PROP_NEXT_SIBLING,
            self::tag('dom_norm_skip_next')
        );
        $context->builder->store($skipNext, $curAlloca);
        $context->builder->branch($bbLoop);

        // Text stand-in with non-empty textContent: merge following adjacent #text.
        // NestedJIT DomRegistry text nodes often have empty LLVM string slots — skip
        // those so we do not wipe a correct NestedJIT normalize (#33438).
        $context->builder->positionAtEnd($bbText);
        $curText = self::loadTextContent($context, $cur);
        $curLen = self::stringLength($context, $curText);
        $bbMerge = BasicBlockHelper::append($context, self::tag('dom_norm_merge'));
        $bbSkipEmpty = BasicBlockHelper::append($context, self::tag('dom_norm_skip_empty'));
        $emptyText = $context->builder->icmp(Builder::INT_EQ, $curLen, $i64->constInt(0, false));
        $context->builder->branchIf($emptyText, $bbSkipEmpty, $bbMerge);

        $context->builder->positionAtEnd($bbSkipEmpty);
        $skipEmptyNext = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $cur,
            VmDom::PROP_NEXT_SIBLING,
            self::tag('dom_norm_skip_empty_next')
        );
        $context->builder->store($skipEmptyNext, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbMerge);
        self::mergeFollowingTextSiblings($context, $parent, $cur, $curAlloca, $aggAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);
        // Parent textContent slot is not aggregated on append of #text stand-ins (#33438).
        self::refreshParentTextContent($context, $parent, $aggAlloca);
        $aggFinal = $context->builder->load($aggAlloca);
        $aggFinalLen = self::stringLength($context, $aggFinal);
        $bbRebuild = BasicBlockHelper::append($context, self::tag('dom_norm_rebuild'));
        $bbNoRebuild = BasicBlockHelper::append($context, self::tag('dom_norm_norebuild'));
        $didMerge = $context->builder->icmp(
            Builder::INT_NE,
            $aggFinalLen,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($didMerge, $bbRebuild, $bbNoRebuild);
        $context->builder->positionAtEnd($bbRebuild);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
        $context->builder->branch($bbNoRebuild);
        $context->builder->positionAtEnd($bbNoRebuild);
    }

    /**
     * Merge cur with each following adjacent #text sibling; leave curAlloca on cur.next
     * after the run. Accumulates merged text into aggAlloca for parent textContent.
     */
    private static function mergeFollowingTextSiblings(
        Context $context,
        Value $parent,
        Value $cur,
        Value $curAlloca,
        Value $aggAlloca
    ): void {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $sibAlloca = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $accAlloca = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));

        $curText = self::loadTextContent($context, $cur);
        $context->builder->store($curText, $accAlloca);
        $aggSoFar = $context->builder->load($aggAlloca);
        $context->builder->store(
            JitStringConcat::concat($context, $aggSoFar, $curText, false),
            $aggAlloca
        );

        $firstSib = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $cur,
            VmDom::PROP_NEXT_SIBLING,
            self::tag('dom_norm_sib0')
        );
        $context->builder->store($firstSib, $sibAlloca);

        $bbLoop = BasicBlockHelper::append($context, self::tag('dom_norm_sib_loop'));
        $bbBody = BasicBlockHelper::append($context, self::tag('dom_norm_sib_body'));
        $bbEnd = BasicBlockHelper::append($context, self::tag('dom_norm_sib_end'));
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $sib = $context->builder->load($sibAlloca);
        $sibNull = $context->builder->icmp(Builder::INT_EQ, $sib, $objPtrTy->constNull());
        $context->builder->branchIf($sibNull, $bbEnd, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $sibIsText = self::isTextStandin($context, $sib);
        $bbEat = BasicBlockHelper::append($context, self::tag('dom_norm_sib_eat'));
        $bbStop = BasicBlockHelper::append($context, self::tag('dom_norm_sib_stop'));
        $context->builder->branchIf($sibIsText, $bbEat, $bbStop);

        $context->builder->positionAtEnd($bbStop);
        $context->builder->store($sib, $curAlloca);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbEat);
        $sibText = self::loadTextContent($context, $sib);
        $sibLen = self::stringLength($context, $sibText);
        $sibNext = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $sib,
            VmDom::PROP_NEXT_SIBLING,
            self::tag('dom_norm_sib_next')
        );
        $bbConcat = BasicBlockHelper::append($context, self::tag('dom_norm_sib_concat'));
        $bbAfterConcat = BasicBlockHelper::append($context, self::tag('dom_norm_sib_after'));
        $sibEmpty = $context->builder->icmp(Builder::INT_EQ, $sibLen, $i64->constInt(0, false));
        $context->builder->branchIf($sibEmpty, $bbAfterConcat, $bbConcat);

        $context->builder->positionAtEnd($bbConcat);
        $acc = $context->builder->load($accAlloca);
        $combined = JitStringConcat::concat($context, $acc, $sibText, false);
        $context->builder->store($combined, $accAlloca);
        $agg2 = $context->builder->load($aggAlloca);
        $context->builder->store(
            JitStringConcat::concat($context, $agg2, $sibText, false),
            $aggAlloca
        );
        $context->builder->branch($bbAfterConcat);

        $context->builder->positionAtEnd($bbAfterConcat);
        JitDomRemoveChildLiveSlots::sync($context, $parent, $sib);
        $context->builder->store($sibNext, $sibAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbEnd);
        $finalAcc = $context->builder->load($accAlloca);
        JitDomCreateTextNode::overwriteCharacterDataValue($context, $cur, $finalAcc);
        // Advance past the merged text node unless stop already set curAlloca to a non-text sib.
        $afterCur = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $cur,
            VmDom::PROP_NEXT_SIBLING,
            self::tag('dom_norm_after_cur')
        );
        $stored = $context->builder->load($curAlloca);
        // If stop branch stored a non-text sibling, keep it; else advance past cur.
        $stillCur = $context->builder->icmp(Builder::INT_EQ, $stored, $cur);
        $bbAdv = BasicBlockHelper::append($context, self::tag('dom_norm_adv'));
        $bbKeep = BasicBlockHelper::append($context, self::tag('dom_norm_keep'));
        $bbMergeAdv = BasicBlockHelper::append($context, self::tag('dom_norm_merge_adv'));
        $context->builder->branchIf($stillCur, $bbAdv, $bbKeep);
        $context->builder->positionAtEnd($bbAdv);
        $context->builder->store($afterCur, $curAlloca);
        $context->builder->branch($bbMergeAdv);
        $context->builder->positionAtEnd($bbKeep);
        $context->builder->branch($bbMergeAdv);
        $context->builder->positionAtEnd($bbMergeAdv);
    }

    private static function refreshParentTextContent(Context $context, Value $parent, Value $aggAlloca): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, self::tag('dom_norm_parent_tc'));
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'textContent')) {
            $objectType->defineProperty($elementClassId, 'textContent', JITVariable::TYPE_STRING);
        }
        $agg = $context->builder->load($aggAlloca);
        $aggLen = self::stringLength($context, $agg);
        $i64 = $context->getTypeFromString('int64');
        $bbWrite = BasicBlockHelper::append($context, self::tag('dom_norm_parent_tc_write'));
        $bbSkip = BasicBlockHelper::append($context, self::tag('dom_norm_parent_tc_skip'));
        $hasText = $context->builder->icmp(Builder::INT_NE, $aggLen, $i64->constInt(0, false));
        $context->builder->branchIf($hasText, $bbWrite, $bbSkip);

        $context->builder->positionAtEnd($bbWrite);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $agg
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', 'textContent'),
            $propVar,
            JITVariable::TYPE_STRING
        );
        $context->builder->branch($bbSkip);
        $context->builder->positionAtEnd($bbSkip);
    }

    private static function isTextStandin(Context $context, Value $node): Value
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'nodeName')) {
            $objectType->defineProperty($elementClassId, 'nodeName', JITVariable::TYPE_STRING);
        }
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'nodeName',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $textLit = $context->builder->load($context->constantStringFromString('#text'));
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $textLit),
            $i64->constInt(0, false)
        );
    }

    private static function loadTextContent(Context $context, Value $node): Value
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'textContent')) {
            $objectType->defineProperty($elementClassId, 'textContent', JITVariable::TYPE_STRING);
        }
        $var = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'textContent',
            $elementClassId
        );

        return $context->helper->loadValue($var);
    }

    private static function stringLength(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function ensureLayout(Context $context): void
    {
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
            VmDom::PROP_CHILD_NODES,
            'nodeName',
            'textContent',
            'nodeValue',
            'data',
            'wholeText',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
        ] as $prop) {
            $type = \in_array($prop, ['nodeName', 'textContent', 'nodeValue', 'data', 'wholeText', VmDom::PROP_USER_SCRIPT_INNER_XML], true)
                ? JITVariable::TYPE_STRING
                : JITVariable::TYPE_VALUE;
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, $type);
            }
        }
    }
}
