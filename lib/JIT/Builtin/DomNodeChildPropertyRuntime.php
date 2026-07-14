<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/** JIT/AOT link for DOMNode::$firstChild / $lastChild live reads (#18951). */
final class DomNodeChildPropertyRuntime
{
    public const ABI_FIRST_CHILD = '__phpc_dom_node_first_child';

    public const ABI_LAST_CHILD = '__phpc_dom_node_last_child';

    public const ABI_FIRST_CHILD_BY_ID = '__phpc_dom_node_first_child_by_id';

    public const ABI_LAST_CHILD_BY_ID = '__phpc_dom_node_last_child_by_id';

    private const HELPER_PATH = '/ext/dom/DomNodeChildPropertyJitHelper.php';

    public const HELPER_FIRST = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::firstChildArgv';

    public const HELPER_LAST = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::lastChildArgv';

    public const HELPER_FIRST_BY_ID = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::firstChildByIdArgv';

    public const HELPER_LAST_BY_ID = 'PHPCompiler\\ext\\dom\\DomNodeChildPropertyJitHelper::lastChildByIdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_FIRST,
        self::HELPER_LAST,
    ];

    public static function abiFor(string $propName): string
    {
        return 'lastchild' === strtolower($propName) ? self::ABI_LAST_CHILD : self::ABI_FIRST_CHILD;
    }

    public static function ensureLinked(Context $context, string $propName): void
    {
        $propLc = strtolower($propName);
        $abi = self::abiFor($propName);
        $helper = 'lastchild' === $propLc ? self::HELPER_LAST : self::HELPER_FIRST;
        $entry = 'lastchild' === $propLc ? 'dom_last_child_bridge' : 'dom_first_child_bridge';

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
}
