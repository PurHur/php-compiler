<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ABI `__phpc_url_rewriter_apply` — NestedJIT UrlRewriterApplyJitHelper (#31099).
 *
 * NestedJIT must run during user-script rewrite lowering ({@see ensureUrlRewriterStack} /
 * emitAdd), not during Context init ObStorage::implement — init-time NestedJIT
 * miscompiles substr and int→string in this helper TU while later StringNl2br
 * NestedJIT in the same module stays correct (#31099).
 *
 * Ob flush always calls this ABI, so ObStorage init installs an identity stub
 * (peer {@see ObStorageLlvm::ensureGzhandlerFlushStub}) for link (#31663). Rewrite
 * path upgrades the stub to the NestedJIT bridge via {@see ensureNestedJitBridge}.
 */
final class UrlRewriterApplyRuntime
{
    private const ABI = '__phpc_url_rewriter_apply';

    /** @var list<string> */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmUrlRewriterHrefApply.php',
        '/ext/standard/UrlRewriterApplyJitHelper.php',
    ];

    private const APPLY_HELPER = 'PHPCompiler\\ext\\standard\\UrlRewriterApplyJitHelper::applyArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::APPLY_HELPER,
    ];

    private const BRIDGE_ENTRY = 'url_rewriter_apply_bridge_entry';

    /** Identity body until NestedJIT rewrite bridge (#31663). */
    private const IDENTITY_STUB_ENTRY = 'ura_identity_stub';

    /** Declare ABI only (no body) — safe during ObStorage init. */
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

    /**
     * Identity stub for ObStorage / init — must not NestedJIT (#31099 / #31663).
     * Peer: ObStorageLlvm::ensureGzhandlerFlushStub.
     */
    public static function ensureLinked(Context $context): void
    {
        self::ensureIdentityStub($context);
    }

    /**
     * NestedJIT PHP apply + BSS bridge — call from ensureUrlRewriterStack only
     * (user-script rewrite path, not Context init).
     */
    public static function ensureNestedJitBridge(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            self::ensureIdentityStub($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @deprecated use ensureLinked — kept for emitAdd call sites */
    public static function emitInstallHook(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** Identity `__phpc_url_rewriter_apply` so thin AOT links without rewrite NestedJIT (#31663). */
    private static function ensureIdentityStub(Context $context): void
    {
        self::declareAbi($context);
        $fn = $context->lookupFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($fn, self::IDENTITY_STUB_ENTRY)
            || $fn->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $fn);

            return;
        }
        $entry = $fn->appendBasicBlock(self::IDENTITY_STUB_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($fn->getParam(0));
        $context->builder->clearInsertionPosition();
        $context->registerFunction(self::ABI, $fn);
    }

    private static function implementBridge(Context $context): void
    {
        OutputRewriteVarsStorage::ensureGlobals($context);
        OutputRewriteVarsStorage::ensureLibc($context);
        self::ensureStringInit($context);

        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#31099',
            true
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        self::declareAbi($context);
        $fn = $context->lookupFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, self::BRIDGE_ENTRY)) {
            return;
        }

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::APPLY_HELPER, '#31099');
        $entry = self::openBridgeEntryBlock($context, $fn);
        $passthrough = $fn->appendBasicBlock('ura_php_passthrough');
        $work = $fn->appendBasicBlock('ura_php_work');
        $context->builder->positionAtEnd($entry);

        $content = $fn->getParam(0);
        $appLen = $context->builder->load(
            OutputRewriteVarsStorage::lenPtrPublic($context, OutputRewriteVarsStorage::GLOBAL_URL_APP_LEN)
        );
        $emptyApp = $context->builder->icmp(Builder::INT_EQ, $appLen, $i64->constInt(0, false));
        $context->builder->branchIf($emptyApp, $passthrough, $work);

        $context->builder->positionAtEnd($passthrough);
        $context->builder->returnValue($content);

        $context->builder->positionAtEnd($work);
        $urlApp = OutputRewriteVarsStorage::stringFromGlobal(
            $context,
            OutputRewriteVarsStorage::GLOBAL_URL_APP,
            OutputRewriteVarsStorage::GLOBAL_URL_APP_LEN
        );
        $contentArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $content,
            $helperFn->getParam(0)->typeOf()
        );
        $urlArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $urlApp,
            $helperFn->getParam(1)->typeOf()
        );
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$contentArg, $urlArg]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction(self::ABI, $fn);
    }

    /**
     * Fresh BRIDGE_ENTRY, or upgrade identity stub by erasing its return and branching in (#31663).
     *
     * @param \PHPLLVM\Value\Function_ $fn
     *
     * @return \PHPLLVM\BasicBlock
     */
    private static function openBridgeEntryBlock(Context $context, $fn)
    {
        if (0 === $fn->countBasicBlocks()) {
            return $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        }
        if (!JitVmHelperLink::hasNamedBridgeEntry($fn, self::IDENTITY_STUB_ENTRY)) {
            throw new \LogicException(self::ABI.' already has a non-bridge body (#31099/#31663)');
        }
        $stub = null;
        foreach ($fn->getBasicBlocks() as $block) {
            if ($block->getName() === self::IDENTITY_STUB_ENTRY) {
                $stub = $block;
                break;
            }
        }
        if (null === $stub) {
            throw new \LogicException(self::ABI.' identity stub block missing (#31663)');
        }
        $term = $stub->getTerminator();
        if (null !== $term && $term instanceof \PHPLLVM\Value\Instruction) {
            $term->eraseFromParent();
        }
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($stub);
        $context->builder->branch($entry);
        $context->builder->clearInsertionPosition();

        return $entry;
    }

    private static function ensureStringInit(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ([
            '__string__init' => $context->context->functionType($strPtr, false, $i64, $i8p),
            'malloc' => $context->context->functionType($i8p, false, $sizeT),
        ] as $name => $ft) {
            \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
        }
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);
    }
}
