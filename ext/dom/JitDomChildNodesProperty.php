<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
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
 * Lazily attach an empty NodeList when unset (createElement before any child sync).
 */
final class JitDomChildNodesProperty
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const CLASS_NODELIST = 'DOMNodeList';

    /** Set when user script reads ->childNodes (foreach snapshot #33082). */
    private static bool $lastFetchWasChildNodes = false;

    public static function isDomChildNodesProperty(string $classLc, string $propLc): bool
    {
        if ('childnodes' !== strtolower($propLc)) {
            return false;
        }
        $classLc = strtolower(str_replace('/', '\\', ltrim($classLc, '\\')));

        return str_starts_with($classLc, 'dom');
    }

    public static function lastFetchWasChildNodes(): bool
    {
        return self::$lastFetchWasChildNodes;
    }

    public static function clearChildNodesFetchHint(): void
    {
        self::$lastFetchWasChildNodes = false;
    }

    public static function fetch(Object_ $objectType, Value $obj): JITVariable
    {
        $context = $objectType->jitContext();
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnodes_fetch');

        // XPath query()/evaluate() NodeLists share GLOBAL_COUNT; clear so item()
        // on this list does not materialize the XPath snapshot (#32620).
        JitDomXPathQueryUserScript::clearQueryState();
        // Stale getElementsByTagName tag must not steal childNodes foreach (#33082).
        JitDomGetElementsByTagNameUserScript::clearTagQuery();
        self::$lastFetchWasChildNodes = true;

        $slotClass = self::slotClass();
        $slotClassId = $objectType->lookup($slotClass);
        if (!$objectType->hasProperty($slotClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($slotClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }

        self::ensureEmptyListIfMissing($objectType, $obj, $slotClass);

        $result = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            $slotClass,
            VmDom::PROP_CHILD_NODES,
            $slotClassId
        );
        $result->classUserType = self::CLASS_NODELIST;

        return $result;
    }

    /** Elements use DOMElement layout; documents keep DOMNode/DOMDocument slots. */
    private static function slotClass(): string
    {
        // Fetch receivers are almost always DOMElement (documentElement / createElement).
        // Document::$childNodes is rare in user scripts; LiveSlots parents are elements.
        return self::CLASS_ELEMENT;
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
        JitDomDocumentElement::storeChildNodesLength($context, $obj, 0);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }
}
