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
        self::ensureBridge(
            $context,
            DomSaveXMLRuntime::ABI_NAME,
            'dom_save_xml_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomSaveXMLJitHelper::saveXMLArgv',
            '/ext/dom/DomSaveXMLJitHelper.php'
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
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
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
}
