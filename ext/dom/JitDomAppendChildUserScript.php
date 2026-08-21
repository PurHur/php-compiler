<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** User-script AOT DOMDocument::appendChild() — documentElement store (#18927, #21687). */
final class JitDomAppendChildUserScript
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const PROP_DOCUMENT_ELEMENT = 'documentElement';

    /**
     * Document::appendChild — expand DocumentFragment children onto the document (#33564).
     *
     * php-src moves fragment children; linking the fragment itself left
     * {@code firstChild=#document-fragment} and SIGSEGV'd on {@code documentElement->tagName}.
     */
    public static function invokeDocumentAppendMaybeFragment(
        Context $context,
        JITVariable $documentVar,
        JITVariable $childVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_maybe_frag');
        if (JitDomRequireDomNodeArg::guardOrAbort($context, $childVar, 'DOMNode::appendChild', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }
        $child = self::loadObjectArg($context, $childVar);
        $retTy = $context->getTypeFromString('__value__*');
        $retAlloca = BasicBlockHelper::entryAlloca($context, $retTy);
        $isFrag = JitDomAppendChildLiveSlots::isDocumentFragmentNode($context, $child);
        $bbFrag = BasicBlockHelper::append($context, 'dom_doc_ac_frag');
        $bbNormal = BasicBlockHelper::append($context, 'dom_doc_ac_normal');
        $bbMerge = BasicBlockHelper::append($context, 'dom_doc_ac_merge');
        $context->builder->branchIf($isFrag, $bbFrag, $bbNormal);

        $context->builder->positionAtEnd($bbFrag);
        $fragRet = self::expandFragmentOntoDocument($context, $documentVar, $child);
        $context->builder->store($fragRet, $retAlloca);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbNormal);
        $normalRet = self::invokeDocumentAppend($context, $documentVar, $childVar);
        $context->builder->store($normalRet, $retAlloca);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);

        return $context->builder->load($retAlloca);
    }

    /**
     * Move each fragment child onto the document; return the fragment (php-src) (#33564).
     */
    private static function expandFragmentOntoDocument(
        Context $context,
        JITVariable $documentVar,
        Value $fragment
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_frag_expand');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');

        $firstSlot = $objectType->propertySlotFor($fragment, 'DOMElement', VmDom::PROP_FIRST_CHILD);
        $firstPtr = $context->builder->load($firstSlot);
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstPtr, $voidPtr->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_doc_ac_frag_empty');
        $bbRead = BasicBlockHelper::append($context, 'dom_doc_ac_frag_read');
        $bbLoop = BasicBlockHelper::append($context, 'dom_doc_ac_frag_loop');
        $bbBody = BasicBlockHelper::append($context, 'dom_doc_ac_frag_body');
        $bbDone = BasicBlockHelper::append($context, 'dom_doc_ac_frag_done');
        $context->builder->branchIf($firstSlotNull, $bbEmpty, $bbRead);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbRead);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstPtr, $context->getTypeFromString('__value__*'))
        );
        // Clear fragment child list before moving (php-src leaves fragment empty).
        $nullBox = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullBox);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullJit = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $nullPtr);
        $objectType->propertyStore($firstSlot, $nullJit, JITVariable::TYPE_VALUE);
        $objectType->propertyStore(
            $objectType->propertySlotFor($fragment, 'DOMElement', VmDom::PROP_LAST_CHILD),
            $nullJit,
            JITVariable::TYPE_VALUE
        );

        $curAlloca = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($firstObj, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curAlloca);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PREVIOUS_SIBLING)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PREVIOUS_SIBLING, JITVariable::TYPE_VALUE);
        }
        $nextSlot = $objectType->propertySlotFor($cur, 'DOMElement', VmDom::PROP_NEXT_SIBLING);
        $nextPtr = $context->builder->load($nextSlot);
        $nextSlotNull = $context->builder->icmp(Builder::INT_EQ, $nextPtr, $voidPtr->constNull());
        $bbNextNull = BasicBlockHelper::append($context, 'dom_doc_ac_frag_next_null');
        $bbNextRead = BasicBlockHelper::append($context, 'dom_doc_ac_frag_next_read');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_doc_ac_frag_after_next');
        $context->builder->branchIf($nextSlotNull, $bbNextNull, $bbNextRead);
        $context->builder->positionAtEnd($bbNextNull);
        $context->builder->branch($bbAfterNext);
        $context->builder->positionAtEnd($bbNextRead);
        $nextObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($nextPtr, $context->getTypeFromString('__value__*'))
        );
        $context->builder->branch($bbAfterNext);
        $context->builder->positionAtEnd($bbAfterNext);
        $nextPhi = $context->builder->phi($objPtrTy);
        $nextPhi->addIncoming($objPtrTy->constNull(), $bbNextNull);
        $nextPhi->addIncoming($nextObj, $bbNextRead);
        // Unlink sibling pointers before Document append.
        $objectType->propertyStore($nextSlot, $nullJit, JITVariable::TYPE_VALUE);
        $prevSlot = $objectType->propertySlotFor($cur, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING);
        $objectType->propertyStore($prevSlot, $nullJit, JITVariable::TYPE_VALUE);

        $curJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $cur);
        self::invokeDocumentAppend($context, $documentVar, $curJit);
        $context->builder->store($nextPhi, $curAlloca);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);

        return self::boxObjectResult($context, $fragment);
    }

    public static function invokeDocumentAppend(
        Context $context,
        JITVariable $documentVar,
        JITVariable $childVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ac_store_cont');

        if (JitDomRequireDomNodeArg::guardOrAbort($context, $childVar, 'DOMNode::appendChild', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        $document = self::loadObjectArg($context, $documentVar);
        $child = self::loadObjectArg($context, $childVar);
        $objectType = $context->type->object;
        $childJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $child);

        // #27410: zero old parent's live childNodes length before reparent.
        self::zeroOldParentChildNodesLength($context, $child);

        // Child-list linking is independent of documentElement (#33546).
        // Comments/PIs/text must not become documentElement — only Element stand-ins
        // do (php-src dom_document_append_child). Previously setRoot always wrote
        // documentElement=child, so comment-then-element left a comment root and
        // tagName reads SIGSEGVd.
        //
        // loadXML/loadHTML pin documentElement as TYPE_OBJECT (raw __object__*), not a
        // VALUE box — __value__readObject on that pointer segfaults (#29487 / re-#19212).
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, self::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, self::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        self::ensureDocumentChildLinkLayout($context);
        $docElSlot = $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, self::PROP_DOCUMENT_ELEMENT);
        $docElPtr = $context->builder->load($docElSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $existingRoot = $context->builder->pointerCast($docElPtr, $objPtrTy);
        $docElMissing = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $docElPtr, $voidPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $existingRoot, $objPtrTy->constNull())
        );

        $firstSlot = $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_FIRST_CHILD);
        $firstPtr = $context->builder->load($firstSlot);
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstPtr, $voidPtr->constNull());
        $bbReadFirst = BasicBlockHelper::append($context, 'dom_doc_ac_read_first');
        $bbEmpty = BasicBlockHelper::append($context, 'dom_doc_ac_link_empty');
        $bbAppend = BasicBlockHelper::append($context, 'dom_doc_ac_link_append');
        $bbAfterLink = BasicBlockHelper::append($context, 'dom_doc_ac_after_link');
        $context->builder->branchIf($firstSlotNull, $bbEmpty, $bbReadFirst);

        $context->builder->positionAtEnd($bbReadFirst);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstPtr, $context->getTypeFromString('__value__*'))
        );
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        // No firstChild but loadXML may still have documentElement — treat as non-empty.
        $context->builder->branchIf(
            $context->builder->and($firstNull, $docElMissing),
            $bbEmpty,
            $bbAppend
        );

        $context->builder->positionAtEnd($bbEmpty);
        $objectType->propertyStore($firstSlot, $childJit, JITVariable::TYPE_VALUE);
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_LAST_CHILD),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        self::writeChildNodesListWithOwner($context, $document, 1, $child, null);
        $context->builder->branch($bbAfterLink);

        $context->builder->positionAtEnd($bbAppend);
        // Prefer lastChild; fall back to firstChild, then documentElement as prior sibling.
        $lastSlot = $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_LAST_CHILD);
        $lastPtr = $context->builder->load($lastSlot);
        $lastSlotNull = $context->builder->icmp(Builder::INT_EQ, $lastPtr, $voidPtr->constNull());
        $useFirst = BasicBlockHelper::append($context, 'dom_doc_ac_tail_first');
        $useRoot = BasicBlockHelper::append($context, 'dom_doc_ac_tail_root');
        $useLast = BasicBlockHelper::append($context, 'dom_doc_ac_tail_last');
        $haveTail = BasicBlockHelper::append($context, 'dom_doc_ac_have_tail');
        $context->builder->branchIf($lastSlotNull, $useFirst, $useLast);
        $context->builder->positionAtEnd($useFirst);
        $context->builder->branchIf($firstNull, $useRoot, $haveTail);
        $context->builder->positionAtEnd($useRoot);
        $context->builder->branch($haveTail);
        $context->builder->positionAtEnd($useLast);
        $lastObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($lastPtr, $context->getTypeFromString('__value__*'))
        );
        $lastObjNull = $context->builder->icmp(Builder::INT_EQ, $lastObj, $objPtrTy->constNull());
        $context->builder->branchIf($lastObjNull, $useFirst, $haveTail);
        $context->builder->positionAtEnd($haveTail);
        $tailPhi = $context->builder->phi($objPtrTy);
        $tailPhi->addIncoming($firstObj, $useFirst);
        $tailPhi->addIncoming($existingRoot, $useRoot);
        $tailPhi->addIncoming($lastObj, $useLast);
        $objectType->propertyStore(
            $objectType->propertySlotFor($tailPhi, 'DOMNode', VmDom::PROP_NEXT_SIBLING),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_LAST_CHILD),
            $childJit,
            JITVariable::TYPE_VALUE
        );
        // item0: prefer live firstChild so comment-then-element lists stay ordered (#33546).
        $headFromFirst = BasicBlockHelper::append($context, 'dom_doc_ac_head_first');
        $headFromDe = BasicBlockHelper::append($context, 'dom_doc_ac_head_de');
        $headDone = BasicBlockHelper::append($context, 'dom_doc_ac_head_done');
        $firstPtr2 = $context->builder->load($firstSlot);
        $firstSlotNull2 = $context->builder->icmp(Builder::INT_EQ, $firstPtr2, $voidPtr->constNull());
        $context->builder->branchIf($firstSlotNull2, $headFromDe, $headFromFirst);
        $context->builder->positionAtEnd($headFromFirst);
        $firstObj2 = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstPtr2, $context->getTypeFromString('__value__*'))
        );
        $firstNull2 = $context->builder->icmp(Builder::INT_EQ, $firstObj2, $objPtrTy->constNull());
        $context->builder->branchIf($firstNull2, $headFromDe, $headDone);
        $context->builder->positionAtEnd($headFromDe);
        $context->builder->branch($headDone);
        $context->builder->positionAtEnd($headDone);
        $headPhi = $context->builder->phi($objPtrTy);
        $headPhi->addIncoming($firstObj2, $headFromFirst);
        $headPhi->addIncoming($existingRoot, $headFromDe);
        self::writeChildNodesListWithOwner($context, $document, 2, $headPhi, $child);
        $context->builder->branch($bbAfterLink);

        $context->builder->positionAtEnd($bbAfterLink);
        // Install documentElement only for Element stand-ins when unset (#33546).
        $isElement = self::isElementStandIn($context, $child);
        $bbSetDe = BasicBlockHelper::append($context, 'dom_doc_ac_set_de');
        $bbAfterDe = BasicBlockHelper::append($context, 'dom_doc_ac_after_de');
        $context->builder->branchIf(
            $context->builder->and($isElement, $docElMissing),
            $bbSetDe,
            $bbAfterDe
        );
        $context->builder->positionAtEnd($bbSetDe);
        $objectType->propertyStore($docElSlot, $childJit, JITVariable::TYPE_OBJECT);
        $context->builder->branch($bbAfterDe);
        $context->builder->positionAtEnd($bbAfterDe);

        // #21687: parentNode on child via DOMElement layout (allocation class).
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        // DocumentType → $doc->doctype for saveXML <!DOCTYPE …> (#33584).
        JitDomDocumentTypeMarkup::storeOnDocumentIfDoctype($context, $document, $child);
        // Pin documentElement only — doctype/comment must not become the dump root (#32361 / #33584).
        $bbPin = BasicBlockHelper::append($context, 'dom_doc_ac_pin_de');
        $bbAfterPin = BasicBlockHelper::append($context, 'dom_doc_ac_after_pin');
        $context->builder->branchIf($isElement, $bbPin, $bbAfterPin);
        $context->builder->positionAtEnd($bbPin);
        DomUserScriptPinnedRootLlvm::pin($context, $child);
        $context->builder->branch($bbAfterPin);
        $context->builder->positionAtEnd($bbAfterPin);

        return self::boxObjectResult($context, $child);
    }

    /**
     * Document::insertBefore — DOMDocument child-edge layout (not LiveSlots/#33584).
     *
     * LiveSlots reads {@code DOMElement::childNodes} which clobbers Document slots
     * (#32736). Mirror {@see storeDocumentAppendChild} VALUE-slot linking.
     */
    public static function insertBeforeOnDocument(
        Context $context,
        Value $document,
        Value $newChild,
        Value $refChild
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_ib');
        self::ensureDocumentChildLinkLayout($context);
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $refJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $refChild);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);

        // prev = ref.previousSibling (DOMElement stand-in layout on children)
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_PREVIOUS_SIBLING, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PARENT_NODE] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $prevSlot = $objectType->propertySlotFor($refChild, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING);
        $prevPtr = $context->builder->load($prevSlot);
        $prevSlotNull = $context->builder->icmp(Builder::INT_EQ, $prevPtr, $voidPtr->constNull());
        $bbPrevNull = BasicBlockHelper::append($context, 'dom_doc_ib_prev_null');
        $bbPrevRead = BasicBlockHelper::append($context, 'dom_doc_ib_prev_read');
        $bbPrevMerge = BasicBlockHelper::append($context, 'dom_doc_ib_prev_merge');
        $context->builder->branchIf($prevSlotNull, $bbPrevNull, $bbPrevRead);

        $context->builder->positionAtEnd($bbPrevNull);
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPrevMerge);
        $context->builder->positionAtEnd($bbPrevRead);
        $prevObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($prevPtr, $context->getTypeFromString('__value__*'))
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPrevMerge);
        $context->builder->positionAtEnd($bbPrevMerge);
        $prev = $context->builder->phi($objPtrTy);
        $prev->addIncoming($objPtrTy->constNull(), $nullPred);
        $prev->addIncoming($prevObj, $readPred);

        // When prev non-null: prev.next = newChild
        $prevIsNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $bbLinkPrev = BasicBlockHelper::append($context, 'dom_doc_ib_link_prev');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_doc_ib_after_prev');
        $context->builder->branchIf($prevIsNull, $bbAfterPrev, $bbLinkPrev);
        $context->builder->positionAtEnd($bbLinkPrev);
        $objectType->propertyStore(
            $objectType->propertySlotFor($prev, 'DOMElement', VmDom::PROP_NEXT_SIBLING),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbAfterPrev);

        // When prev null: document.firstChild = newChild
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_doc_ib_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_doc_ib_after_first');
        $context->builder->branchIf($prevIsNull, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, VmDom::PROP_FIRST_CHILD),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbAfterFirst);

        $context->builder->positionAtEnd($bbAfterFirst);
        $bbPrevNullStore = BasicBlockHelper::append($context, 'dom_doc_ib_prev_null_store');
        $bbPrevObjStore = BasicBlockHelper::append($context, 'dom_doc_ib_prev_obj_store');
        $bbPrevStoreDone = BasicBlockHelper::append($context, 'dom_doc_ib_prev_store_done');
        $context->builder->branchIf($prevIsNull, $bbPrevNullStore, $bbPrevObjStore);
        $context->builder->positionAtEnd($bbPrevNullStore);
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
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING),
            $nullVar,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPrevStoreDone);
        $context->builder->positionAtEnd($bbPrevObjStore);
        $prevJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $prev);
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING),
            $prevJit,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPrevStoreDone);

        $context->builder->positionAtEnd($bbPrevStoreDone);
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_NEXT_SIBLING),
            $refJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($refChild, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );

        // childNodes: [newChild, refChild] for the common insert-before-first case
        self::writeChildNodesListWithOwner($context, $document, 2, $newChild, $refChild);

        // DocumentType → $doc->doctype; Element → pin documentElement when unset
        JitDomDocumentTypeMarkup::storeOnDocumentIfDoctype($context, $document, $newChild);
        $isElement = self::isElementStandIn($context, $newChild);
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        if (!$objectType->hasProperty($docClassId, self::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, self::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        $docElSlot = $objectType->propertySlotFor($document, self::CLASS_DOCUMENT, self::PROP_DOCUMENT_ELEMENT);
        $docElPtr = $context->builder->load($docElSlot);
        $existingRoot = $context->builder->pointerCast($docElPtr, $objPtrTy);
        $docElMissing = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $docElPtr, $voidPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $existingRoot, $objPtrTy->constNull())
        );
        $bbSetDe = BasicBlockHelper::append($context, 'dom_doc_ib_set_de');
        $bbAfterDe = BasicBlockHelper::append($context, 'dom_doc_ib_after_de');
        $context->builder->branchIf(
            $context->builder->and($isElement, $docElMissing),
            $bbSetDe,
            $bbAfterDe
        );
        $context->builder->positionAtEnd($bbSetDe);
        $objectType->propertyStore($docElSlot, $newJit, JITVariable::TYPE_OBJECT);
        DomUserScriptPinnedRootLlvm::pin($context, $newChild);
        $context->builder->branch($bbAfterDe);
        $context->builder->positionAtEnd($bbAfterDe);
    }

    /**
     * True when {@code $node} is an Element stand-in (not comment/text/cdata/fragment/PI/entity-ref/doctype).
     *
     * Public for insertBefore firstElementChild gating (#33584).
     */
    public static function isElementStandInPublic(Context $context, Value $node): Value
    {
        return self::isElementStandIn($context, $node);
    }

    /**
     * True when {@code $node} is an Element stand-in (not comment/text/cdata/fragment/PI/entity-ref/doctype).
     *
     * createComment/createTextNode use DOMElement allocations with {@code nodeName}
     * {@code #comment}/{@code #text}/… — class_id alone cannot discriminate (#33546).
     * createProcessingInstruction / createEntityReference / createDocumentType keep Zend
     * {@code nodeName} as the target/entity/qualified name and stash
     * {@code #pi}/{@code #entity-ref}/{@code #document-type} on tagName
     * (#33556 / #32343 / #33565) — those must not become documentElement either.
     */
    private static function isElementStandIn(Context $context, Value $node): Value
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, 'nodeName')) {
            $objectType->defineProperty($elementClassId, 'nodeName', JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($elementClassId, 'tagName')) {
            $objectType->defineProperty($elementClassId, 'tagName', JITVariable::TYPE_STRING);
        }
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'nodeName',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $nonElement = $i1->constInt(0, false);
        foreach (['#comment', '#text', '#cdata-section', '#document-fragment'] as $lit) {
            $litStr = $context->builder->load($context->constantStringFromString($lit));
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                JitStringCompare::strcmp($context, $nameStr, $litStr),
                $i64->constInt(0, false)
            );
            $nonElement = $context->builder->or($nonElement, $match);
        }
        // PI / entity-ref / DocumentType: Zend nodeName is the target/name; discriminator is tagName.
        $tagVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            'tagName',
            $elementClassId
        );
        $tagStr = $context->helper->loadValue($tagVar);
        foreach ([
            JitDomCreateProcessingInstruction::TAG_KIND,
            JitDomCreateEntityReference::TAG_KIND,
            JitDomCreateDocumentType::TAG_KIND,
        ] as $lit) {
            $litStr = $context->builder->load($context->constantStringFromString($lit));
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                JitStringCompare::strcmp($context, $tagStr, $litStr),
                $i64->constInt(0, false)
            );
            $nonElement = $context->builder->or($nonElement, $match);
        }

        return $context->builder->not($nonElement);
    }

    /** Replace old parent's childNodes with length 0 when child.parentNode is set (#27410). */
    private static function zeroOldParentChildNodesLength(Context $context, Value $child): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }

        $parentSlot = $objectType->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($parentSlot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slotIsNull = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $skip = BasicBlockHelper::append($context, 'dom_doc_ac_skip_unlink');
        $read = BasicBlockHelper::append($context, 'dom_doc_ac_read_parent');
        $do = BasicBlockHelper::append($context, 'dom_doc_ac_unlink');
        $merge = BasicBlockHelper::append($context, 'dom_doc_ac_unlink_merge');
        $context->builder->branchIf($slotIsNull, $skip, $read);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($merge);

        // Null-tagged VALUE slots are non-null pointers — readObject then check (#27410).
        $context->builder->positionAtEnd($read);
        $oldParent = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($slotPtr, $context->getTypeFromString('__value__*'))
        );
        $parentIsNull = $context->builder->icmp(Builder::INT_EQ, $oldParent, $objPtrTy->constNull());
        $context->builder->branchIf($parentIsNull, $skip, $do);

        $context->builder->positionAtEnd($do);
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($oldParent, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    private static function ensureChildLinkLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
    }

    /** Child-link slots on DOMDocument itself — do not reuse DOMNode indices (#32736). */
    private static function ensureDocumentChildLinkLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        self::ensureChildLinkLayout($context);
    }

    private static function writeChildNodesListWithOwner(
        Context $context,
        Value $owner,
        int $length,
        ?Value $item0 = null,
        ?Value $item1 = null
    ): void {
        self::ensureDocumentChildLinkLayout($context);
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($listClassId, '__phpcItem0')) {
            $objectType->defineProperty($listClassId, '__phpcItem0', JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, '__phpcItem1')) {
            $objectType->defineProperty($listClassId, '__phpcItem1', JITVariable::TYPE_VALUE);
        }
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        if (null !== $item0) {
            $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
                $i0,
                JITVariable::TYPE_VALUE
            );
        }
        if (null !== $item1) {
            $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
                $i1,
                JITVariable::TYPE_VALUE
            );
        }
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, self::CLASS_DOCUMENT, VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
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

        throw new \LogicException('DOMDocument::appendChild() expects object nodes');
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
