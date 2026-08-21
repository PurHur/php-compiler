<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpc_deploy_path via DeployPathJitHelper PHP (#585, #9309, #27037, #33225).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringFilterSanitize #27033 / StringFilterUrl #26766).
 *
 * Do not re-add an always-on empty decl in {@see Type} — leftover decls mint phpc_deploy_path.1
 * (#31894 / #32122 / #33225). Thin standalone AOT still cannot NestedJIT this helper without a
 * startup SEGV (peer #33217 strftime); declare-only there so link fails honestly.
 */
final class StringDeployPath
{
    private const HELPER_PATH = '/ext/standard/DeployPathJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\DeployPathJitHelper::resolve';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_phpc_deploy_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_phpc_deploy_path', $probe);

            return;
        }

        // Thin user-script AOT: NestedJIT DeployPathJitHelper SEGV at startup
        // (`main_before_php`, peer #33217). Keep declare-only so link fails with
        // undefined reference — same honest failure as the pre-#33225 empty Type decl.
        if ($context->isThinStandaloneAotMain()) {
            self::declareAbiOnly($context);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StringFilterSanitize #27033) —
        // clearInsertionPosition left the user-script builder detached
        // ("Current basic block has no parent function").
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementDeployPathBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareAbiOnly(Context $context): void
    {
        $abiName = '__compiler_phpc_deploy_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDeployPathBridge(Context $context): void
    {
        $abiName = '__compiler_phpc_deploy_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('deploy_bridge_entry');
        $context->builder->positionAtEnd($entry);
        // NestedJIT string may be __value__*; ABI is __string__* (#26853 / peer StringFilterSanitize).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#27037');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27037'
        );
    }
}
