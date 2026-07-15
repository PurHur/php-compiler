<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;

/**
 * User-script standalone AOT: compile DOMDocument::loadHTML helper in the main module (#17954).
 */
final class DomDocumentMethodUserScriptLlvm
{
    public static function shouldUse(Context $context): bool
    {
        return UserScriptAotDeferNestedJit::shouldDefer($context);
    }

    public static function ensureLoadHTMLBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomLoadHTMLRuntime::ABI_NAME,
            'dom_load_html_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadHTMLJitHelper::loadHTMLArgv',
            '/ext/dom/DomLoadHTMLJitHelper.php'
        );
    }

    public static function ensureLoadBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadRuntime::ABI_NAME,
            'dom_load_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadJitHelper::loadArgv',
            '/ext/dom/DomLoadJitHelper.php'
        );
    }

    public static function ensureLoadHTMLFileBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadHTMLFileRuntime::ABI_NAME,
            'dom_load_html_file_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadHTMLFileJitHelper::loadHTMLFileArgv',
            '/ext/dom/DomLoadHTMLFileJitHelper.php'
        );
    }

    public static function ensureGetElementsByTagNameBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomGetElementsByTagNameRuntime::ABI_NAME,
            'dom_get_elements_by_tag_name_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomGetElementsByTagNameJitHelper::getElementsByTagNameStringArgv',
            '/ext/dom/DomGetElementsByTagNameJitHelper.php'
        );
    }

    public static function ensureGetElementByIdBridge(Context $context): void
    {
        self::ensureNullableObjectValueBridge(
            $context,
            DomGetElementByIdRuntime::ABI_NAME,
            'dom_get_element_by_id_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__value__*'),
            ],
            'PHPCompiler\\ext\\dom\\DomGetElementByIdJitHelper::getElementByIdArgv',
            '/ext/dom/DomGetElementByIdJitHelper.php'
        );
    }

    public static function ensureFirstChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_FIRST_CHILD,
            'dom_first_child_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_FIRST,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureLastChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_LAST_CHILD,
            'dom_last_child_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_LAST,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureXPathQueryBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomXPathQueryRuntime::ABI_NAME,
            'dom_xpath_query_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomXPathQueryJitHelper::queryStringArgv',
            '/ext/dom/DomXPathQueryJitHelper.php'
        );
    }

    public static function ensureXPathEvaluateBridge(Context $context): void
    {
        self::ensureXPathEvaluateBoolBridge($context);
        self::ensureXPathEvaluateDoubleBridge($context);
    }

    public static function ensureXPathEvaluateBoolBridge(Context $context): void
    {
        self::ensureContextBoolValueBridge(
            $context,
            DomXPathEvaluateRuntime::ABI_BOOL,
            'dom_xpath_evaluate_bool_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            self::BOOL_HELPER,
            '/ext/dom/DomXPathEvaluateJitHelper.php'
        );
    }

    public static function ensureXPathEvaluateDoubleBridge(Context $context): void
    {
        self::ensureContextDoubleValueBridge(
            $context,
            DomXPathEvaluateRuntime::ABI_DOUBLE,
            'dom_xpath_evaluate_double_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            self::DOUBLE_HELPER,
            '/ext/dom/DomXPathEvaluateJitHelper.php'
        );
    }

    private const BOOL_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateBoolArgv';

    private const DOUBLE_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateDoubleArgv';

    public static function ensureNodeListItemBridge(Context $context): void
    {
        self::ensureNullableObjectValueBridge(
            $context,
            DomNodeListItemRuntime::ABI_NAME,
            'dom_nodelist_item_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('int64'),
            ],
            'PHPCompiler\\ext\\dom\\DomNodeListItemJitHelper::itemIntArgv',
            '/ext/dom/DomNodeListItemJitHelper.php'
        );
    }

    public static function ensureLoadXMLBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadXMLRuntime::ABI_NAME,
            'dom_load_xml_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadXMLJitHelper::loadXMLArgv',
            '/ext/dom/DomLoadXMLJitHelper.php'
        );
    }

    public static function ensureSaveHTMLBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSaveHTMLRuntime::ABI_NAME,
            'dom_save_html_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomSaveHTMLJitHelper::saveHTMLArgv',
            '/ext/dom/DomSaveHTMLJitHelper.php'
        );
    }

    public static function ensureSaveXMLBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomSaveXMLRuntime::ABI_NAME,
            'dom_save_xml_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__value__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomSaveXMLJitHelper::saveXMLArgv',
            '/ext/dom/DomSaveXMLJitHelper.php'
        );
    }

    public static function ensureCreateElementBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomCreateElementRuntime::ABI_NAME,
            'dom_create_element_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createElementArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureCreateElementNSBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomCreateElementNSRuntime::ABI_NAME,
            'dom_create_element_ns_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementNSJitHelper::createElementNSArgv',
            '/ext/dom/DomCreateElementNSJitHelper.php'
        );
    }

    public static function ensureSaveHTMLFileBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSaveHTMLFileRuntime::ABI_NAME,
            'dom_save_html_file_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int64'),
            'PHPCompiler\\ext\\dom\\DomSaveHTMLFileJitHelper::saveHTMLFileArgv',
            '/ext/dom/DomSaveHTMLFileJitHelper.php'
        );
    }

    public static function ensureElementTextContentBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomElementTextContentRuntime::ABI_NAME,
            'dom_element_text_content_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::textContentArgv',
            '/ext/dom/DomElementTextContentJitHelper.php'
        );
    }

    public static function ensureSyncElementIdMapBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSyncElementIdMapRuntime::ABI_NAME,
            'dom_sync_element_id_map_user_script',
            [
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('void'),
            'PHPCompiler\\ext\\dom\\DomSyncElementIdMapJitHelper::syncArgv',
            '/ext/dom/DomSyncElementIdMapJitHelper.php'
        );
    }

    public static function ensureAppendBridge(Context $context, int $arity): void
    {
        self::ensureLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::appendAbi($arity),
            'dom_append_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv'.$arity
        );
    }

    public static function ensureAppendObjectBridge(Context $context, int $arity): void
    {
        self::ensureObjectLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::appendObjectAbi($arity),
            'dom_append_object_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv'.$arity
        );
    }

    public static function ensurePrependBridge(Context $context, int $arity): void
    {
        self::ensureLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::prependAbi($arity),
            'dom_prepend_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv'.$arity
        );
    }

    public static function ensurePrependObjectBridge(Context $context, int $arity): void
    {
        self::ensureObjectLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::prependObjectAbi($arity),
            'dom_prepend_object_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv'.$arity
        );
    }

    public static function ensureAppendStringBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::appendStringAbi(),
            'dom_append_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendStringArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensurePrependStringBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::prependStringAbi(),
            'dom_prepend_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependStringArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureReplaceChildrenBridge(Context $context, int $arity): void
    {
        self::ensureLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::replaceChildrenAbi($arity),
            'dom_replace_children_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomNodeLiveMutationJitHelper::replaceChildrenArgv'.$arity
        );
    }

    public static function ensureCreateDocumentFragmentBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomNodeLiveMutationRuntime::ABI_CREATE_FRAGMENT,
            'dom_create_document_fragment_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureCreateDocumentFragmentObjectBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::ABI_CREATE_FRAGMENT_OBJECT,
            'dom_create_document_fragment_object_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentObjectArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    private static function ensureObjectLiveMutationBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        int $arity,
        string $helperLogical
    ): void {
        $objPtr = $context->getTypeFromString('__object__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $arity; ++$i) {
            $paramTypes[] = $objPtr;
        }
        self::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            $paramTypes,
            $context->context->voidType(),
            $helperLogical,
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    private static function ensureLiveMutationBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        int $arity,
        string $helperLogical
    ): void {
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $arity; ++$i) {
            $paramTypes[] = $valuePtr;
        }
        self::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            $paramTypes,
            $context->context->voidType(),
            $helperLogical,
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        \PHPLLVM\Type $returnType,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18268');
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $result = $context->builder->call($helperFn, ...$args);
        if ('void' === $context->getStringFromType($returnType)) {
            $context->builder->returnVoid();
        } else {
            $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
            $context->builder->returnValue($ret);
        }
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        \PHPLLVM\Type $returnType,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $result = $context->builder->call($helperFn, ...$args);
        if ('void' === $context->getStringFromType($returnType)) {
            $context->builder->returnVoid();
        } else {
            $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
            $context->builder->returnValue($ret);
        }
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextObjectValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18461');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $foundObj
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureNullableObjectValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundObj,
            $objPtr->constNull()
        );
        $nullBlock = $fn->appendBasicBlock('dom_gei_bridge_null');
        $objBlock = $fn->appendBasicBlock('dom_gei_bridge_obj');
        $doneBlock = $fn->appendBasicBlock('dom_gei_bridge_done');
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

    private static function ensureNestedHelperProxies(Context $context): void
    {
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toobject');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    public static function compileMainModuleHelpers(
        Context $context,
        string $relativePath,
        array $compiledHelpers
    ): void {
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $relativePath, $compiledHelpers);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function ensureMainModuleHelperCompiled(
        Context $context,
        string $relativePath,
        array $compiledHelpers
    ): void {
        $missing = false;
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).$relativePath;
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), \basename($path));
            if (null === $block) {
                throw new \LogicException(\basename($path).' parseAndCompile failed (#17954)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for user-script DOM loadHTML bridge (#17954)');
            }
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextBoolValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18526');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $truthy = $context->builder->call($helperFn, ...$args);
        $truthy = JitNestedHelperCoerce::coerceBridgeResult($context, $truthy, $i64);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool(
            $context,
            $destPtr,
            $context->builder->icmp(Builder::INT_NE, $truthy, $i64->constInt(0, false))
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextDoubleValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18526');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $number = $context->builder->call($helperFn, ...$args);
        $number = JitNestedHelperCoerce::coerceBridgeResult($context, $number, $doubleTy);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $destPtr,
            $number
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
