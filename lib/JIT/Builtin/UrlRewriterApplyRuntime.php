<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * ABI `__phpc_url_rewriter_apply` + NestedJIT {@see \PHPCompiler\ext\standard\VmUrlRewriterFlush} (#27566).
 *
 * ObOutput NestedJIT must only *call* the ABI (via {@see emitApplyCall} / kernel) — implementing
 * the body here NestedJITs UrlScannerEx separately so it does not break ObOutput::$stack.
 */
final class UrlRewriterApplyRuntime
{
    private const ABI = '__phpc_url_rewriter_apply';

    private const FLUSH_PATH = '/ext/standard/VmUrlRewriterFlush.php';

    private const SCANNER_PATH = '/ext/standard/UrlScannerEx.php';

    private const OUTPUT_VARS_PATH = '/ext/standard/VmOutputRewriteVars.php';

    private const REWRITE_HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const APPLY_HELPER = 'PHPCompiler\\ext\\standard\\VmUrlRewriterFlush::applyHandler';

    private const ADAPT_HELPER = 'PHPCompiler\\ext\\standard\\UrlScannerEx::adapt';

    private const LIST_PAIRS = 'PHPCompiler\\ext\\standard\\VmOutputRewriteVars::listPairs';

    private const EXPORT_BLOB = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::exportBlob';

    private const GET_TAGS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getTags';

    private const GET_HOSTS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getHosts';

    /** @var list<string> */
    private const BUNDLE = [
        self::REWRITE_HELPER_PATH,
        self::OUTPUT_VARS_PATH,
        self::SCANNER_PATH,
        self::FLUSH_PATH,
    ];

    /** @var list<string> */
    private const HELPERS = [
        self::EXPORT_BLOB,
        self::GET_TAGS,
        self::GET_HOSTS,
        self::LIST_PAIRS,
        self::ADAPT_HELPER,
        self::APPLY_HELPER,
    ];

    /** Declare ABI only (no body) — safe during ObOutput NestedJIT. */
    public static function declareAbi(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::ABI);
        if (null !== $existing) {
            $context->registerFunction(self::ABI, $existing);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->addFunction(self::ABI, $ft);
        $context->registerFunction(self::ABI, $fn);
    }

    public static function emitApplyCall(Context $context, Value $contentStr): Value
    {
        self::declareAbi($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $contentStr);
    }

    /** NestedJIT scanner/flush and implement ABI body. */
    public static function ensureLinked(Context $context): void
    {
        self::declareAbi($context);
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        StringUrlencode::ensureLinked($context);
        \PHPCompiler\ext\standard\JitStreamLifecycleKernel::ensureLinked($context);
        // Force thin is_resource body — NestedJitCompileScope early-return can skip it (#27566).
        StreamGlobalsJit::implementThinIsResource($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::BUNDLE,
            self::HELPERS,
            '#27566'
        );
        $helper = self::helperFunction($context, self::APPLY_HELPER);
        $fn = $context->lookupFunction(self::ABI);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $restore = null;
        try {
            $restore = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        $entry = $fn->appendBasicBlock('url_rewriter_apply_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call($helper, $fn->getParam(0));
        $context->builder->returnValue($result);
        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @deprecated use ensureLinked — kept for emitAdd call sites */
    public static function emitInstallHook(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UrlRewriterApplyRuntime compile (#27566)');
        }

        return $fn;
    }
}
