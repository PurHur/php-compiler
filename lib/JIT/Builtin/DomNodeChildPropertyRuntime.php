<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/** JIT/AOT link for DOMNode live link property reads (#18951, #19240, #19431). */
final class DomNodeChildPropertyRuntime
{
    public const ABI_FIRST_CHILD = '__phpc_dom_node_first_child';

    public const ABI_LAST_CHILD = '__phpc_dom_node_last_child';

    public const ABI_PARENT_NODE = '__phpc_dom_node_parent_node';

    public const ABI_NEXT_SIBLING = '__phpc_dom_node_next_sibling';

    public const ABI_PREVIOUS_SIBLING = '__phpc_dom_node_previous_sibling';

    public const ABI_FIRST_ELEMENT_CHILD = '__phpc_dom_node_first_element_child';

    public const ABI_LAST_ELEMENT_CHILD = '__phpc_dom_node_last_element_child';

    public const ABI_CHILD_ELEMENT_COUNT = '__phpc_dom_node_child_element_count';

    public const ABI_NEXT_ELEMENT_SIBLING = '__phpc_dom_node_next_element_sibling';

    public const ABI_PREVIOUS_ELEMENT_SIBLING = '__phpc_dom_node_previous_element_sibling';

    public const ABI_FIRST_CHILD_BY_ID = '__phpc_dom_node_first_child_by_id';

    public const ABI_LAST_CHILD_BY_ID = '__phpc_dom_node_last_child_by_id';

    private const HELPER_PATH = '/ext/dom/DomNodeChildPropertyJitHelper.php';

    public const HELPER_FIRST = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::firstChildArgv';

    public const HELPER_LAST = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::lastChildArgv';

    public const HELPER_PARENT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::parentNodeArgv';

    public const HELPER_NEXT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::nextSiblingArgv';

    public const HELPER_PREV = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::previousSiblingArgv';

    public const HELPER_FIRST_ELEMENT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::firstElementChildArgv';

    public const HELPER_LAST_ELEMENT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::lastElementChildArgv';

    public const HELPER_CHILD_ELEMENT_COUNT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::childElementCountArgv';

    public const HELPER_NEXT_ELEMENT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::nextElementSiblingArgv';

    public const HELPER_PREV_ELEMENT = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::previousElementSiblingArgv';

    public const HELPER_FIRST_BY_ID = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::firstChildByIdArgv';

    public const HELPER_LAST_BY_ID = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::lastChildByIdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_FIRST,
        self::HELPER_LAST,
        self::HELPER_PARENT,
        self::HELPER_NEXT,
        self::HELPER_PREV,
        self::HELPER_FIRST_ELEMENT,
        self::HELPER_LAST_ELEMENT,
        self::HELPER_CHILD_ELEMENT_COUNT,
        self::HELPER_NEXT_ELEMENT,
        self::HELPER_PREV_ELEMENT,
    ];

    public static function abiFor(string $propName): string
    {
        return match (strtolower($propName)) {
            'lastchild' => self::ABI_LAST_CHILD,
            'parentnode' => self::ABI_PARENT_NODE,
            'nextsibling' => self::ABI_NEXT_SIBLING,
            'previoussibling' => self::ABI_PREVIOUS_SIBLING,
            'firstelementchild' => self::ABI_FIRST_ELEMENT_CHILD,
            'lastelementchild' => self::ABI_LAST_ELEMENT_CHILD,
            'childelementcount' => self::ABI_CHILD_ELEMENT_COUNT,
            'nextelementsibling' => self::ABI_NEXT_ELEMENT_SIBLING,
            'previouselementsibling' => self::ABI_PREVIOUS_ELEMENT_SIBLING,
            default => self::ABI_FIRST_CHILD,
        };
    }

    public static function helperFor(string $propName): string
    {
        return match (strtolower($propName)) {
            'lastchild' => self::HELPER_LAST,
            'parentnode' => self::HELPER_PARENT,
            'nextsibling' => self::HELPER_NEXT,
            'previoussibling' => self::HELPER_PREV,
            'firstelementchild' => self::HELPER_FIRST_ELEMENT,
            'lastelementchild' => self::HELPER_LAST_ELEMENT,
            'childelementcount' => self::HELPER_CHILD_ELEMENT_COUNT,
            'nextelementsibling' => self::HELPER_NEXT_ELEMENT,
            'previouselementsibling' => self::HELPER_PREV_ELEMENT,
            default => self::HELPER_FIRST,
        };
    }

    public static function isLinkProperty(string $propLc): bool
    {
        return \in_array(strtolower($propLc), [
            'firstchild',
            'lastchild',
            'parentnode',
            'nextsibling',
            'previoussibling',
            'firstelementchild',
            'lastelementchild',
            'childelementcount',
            'nextelementsibling',
            'previouselementsibling',
        ], true);
    }

    public static function ensureLinked(Context $context, string $propName): void
    {
        $propLc = strtolower($propName);
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            match ($propLc) {
                'lastchild' => JitDomDocumentMethodKernel::ensureLastChildBridge($context),
                'parentnode' => JitDomDocumentMethodKernel::ensureParentNodeBridge($context),
                'nextsibling' => JitDomDocumentMethodKernel::ensureNextSiblingBridge($context),
                'previoussibling' => JitDomDocumentMethodKernel::ensurePreviousSiblingBridge($context),
                'firstelementchild' => JitDomDocumentMethodKernel::ensureFirstElementChildBridge($context),
                'lastelementchild' => JitDomDocumentMethodKernel::ensureLastElementChildBridge($context),
                'childelementcount' => JitDomDocumentMethodKernel::ensureChildElementCountBridge($context),
                'nextelementsibling' => JitDomDocumentMethodKernel::ensureNextElementSiblingBridge($context),
                'previouselementsibling' => JitDomDocumentMethodKernel::ensurePreviousElementSiblingBridge($context),
                default => JitDomDocumentMethodKernel::ensureFirstChildBridge($context),
            };

            return;
        }

        if ('childelementcount' === $propLc) {
            self::ensureChildElementCountLinked($context);

            return;
        }

        $abi = self::abiFor($propName);
        $helper = self::helperFor($propName);
        $entry = match ($propLc) {
            'lastchild' => 'dom_last_child_bridge',
            'parentnode' => 'dom_parent_node_bridge',
            'nextsibling' => 'dom_next_sibling_bridge',
            'previoussibling' => 'dom_previous_sibling_bridge',
            'firstelementchild' => 'dom_first_element_child_bridge',
            'lastelementchild' => 'dom_last_element_child_bridge',
            'nextelementsibling' => 'dom_next_element_sibling_bridge',
            'previouselementsibling' => 'dom_previous_element_sibling_bridge',
            default => 'dom_first_child_bridge',
        };

        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18951');

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helper, '#18951');
        $ft = $context->context->functionType($valuePtr, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $bridgeEntry = JitVmHelperLink::bridgeEntryForEmit($fn, $entry);
        $context->builder->positionAtEnd($bridgeEntry);
        $foundObj = $context->builder->call($helperFn, $fn->getParam(0));
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundObj,
            $objPtr->constNull()
        );
        $nullBlock = $fn->appendBasicBlock('dom_child_prop_bridge_null');
        $objBlock = $fn->appendBasicBlock('dom_child_prop_bridge_obj');
        $doneBlock = $fn->appendBasicBlock('dom_child_prop_bridge_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($objBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $foundObj
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureChildElementCountLinked(Context $context): void
    {
        $abi = self::ABI_CHILD_ELEMENT_COUNT;
        $entry = 'dom_child_element_count_bridge';
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#19431');

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER_CHILD_ELEMENT_COUNT, '#19431');
        $ft = $context->context->functionType($i64, false, $objPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $bridgeEntry = JitVmHelperLink::bridgeEntryForEmit($fn, $entry);
        $context->builder->positionAtEnd($bridgeEntry);
        $count = $context->builder->call($helperFn, $fn->getParam(0));
        $context->builder->returnValue($count);
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
