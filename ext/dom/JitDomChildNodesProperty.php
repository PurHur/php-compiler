<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::$childNodes (php-src ext/dom/node.c).
 *
 * Element allocations must read/write {@see CLASS_ELEMENT}::{@see VmDom::PROP_CHILD_NODES}
 * — same layout as firstChild/siblings (#27476). Using DOMNode indices on a DOMElement
 * object is out-of-bounds / corrupts tagName (#24973) and SIGSEGVs on ->length.
 *
 * Document receivers must use {@see CLASS_DOCUMENT} — LiveSlots / appendChild write
 * that layout (#34160). Always fetching Element slots left Document lists invisible
 * (length 0 / item SIGSEGV after ChildNode::before/after on documentElement).
 *
 * Lazily attach an empty NodeList when unset (createElement before any child sync).
 */
final class JitDomChildNodesProperty
{
    private const CLASS_DOCUMENT = 'DOMDocument';

    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_NODELIST = 'DOMNodeList';

    public static function isDomChildNodesProperty(string $classLc, string $propLc): bool
    {
        if ('childnodes' !== strtolower($propLc)) {
            return false;
        }
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));

        return str_starts_with($classLc, 'dom');
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnodes_fetch');

        // XPath query()/evaluate() NodeLists share GLOBAL_COUNT; clear so item()
        // on this list does not materialize the XPath snapshot (#32620).
        JitDomXPathQueryUserScript::clearQueryState();
        // Stamp for thin-AOT foreach snapshot (#33082) — CFG userType is often unset.
        JitDomNodeListForeachSnapshot::markChildNodesFetch();

        $docClassId = $objectType->lookup(self::CLASS_DOCUMENT);
        $elClassId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($elClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($elClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }

        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $isDoc = JitDomParentChildLinkLayout::isDocumentObject($context, $obj, 'dom_cn_fetch');
        $bbDoc = BasicBlockHelper::append($context, 'dom_cn_fetch_doc');
        $bbEl = BasicBlockHelper::append($context, 'dom_cn_fetch_el');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_fetch_done');
        $context->builder->branchIf($isDoc, $bbDoc, $bbEl);

        $context->builder->positionAtEnd($bbDoc);
        self::ensureEmptyListIfMissing($objectType, $obj, self::CLASS_DOCUMENT);
        $docList = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_DOCUMENT,
            VmDom::PROP_CHILD_NODES,
            $docClassId
        );
        $context->builder->store(
            JitValueBox::valuePtrFromVariable($context, $docList),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbEl);
        self::ensureEmptyListIfMissing($objectType, $obj, self::CLASS_ELEMENT);
        $elList = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            self::CLASS_ELEMENT,
            VmDom::PROP_CHILD_NODES,
            $elClassId
        );
        $context->builder->store(
            JitValueBox::valuePtrFromVariable($context, $elList),
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
        $result->classUserType = self::CLASS_NODELIST;

        return $result;
    }

    /**
     * If childNodes is unset, attach length-0 list (Zend always returns a live NodeList).
     */
    private static function ensureEmptyListIfMissing(Object_ $objectType, Value $obj, string $slotClass): void
    {
        $context = $objectType->jitContext();
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slot = $objectType->propertySlotFor($obj, $slotClass, VmDom::PROP_CHILD_NODES);
        $ptr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $voidPtr->constNull());

        $bbCheckObj = BasicBlockHelper::append($context, 'dom_cn_chk_obj');
        $bbSeed = BasicBlockHelper::append($context, 'dom_cn_seed');
        $bbDone = BasicBlockHelper::append($context, 'dom_cn_done');
        $context->builder->branchIf($slotNull, $bbSeed, $bbCheckObj);

        $context->builder->positionAtEnd($bbCheckObj);
        $loaded = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($ptr, $context->getTypeFromString('__value__*'))
        );
        $objNull = $context->builder->icmp(Builder::INT_EQ, $loaded, $objPtrTy->constNull());
        $context->builder->branchIf($objNull, $bbSeed, $bbDone);

        $context->builder->positionAtEnd($bbSeed);
        JitDomDocumentElement::storeChildNodesLength($context, $obj, 0, null, null, $slotClass);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }
}
