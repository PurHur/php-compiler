<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_copy via CopyJitHelper PHP (#9585).
 *
 * Type always-on leftover dropped (#32466): declareFunction uses getNamedFunction first
 * so a drifted ABI cannot mint __compiler_copy.1 (#31894 / #32122).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer FsGlobVecRuntime #22205).
 * Replaces {@see StringFsDirJit::emitCopy} libc fread/fwrite/chmod LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::copy()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyRuntime
{
    private const HELPER_PATH = '/ext/standard/CopyJitHelper.php';

    private const COPY_HELPER = 'PHPCompiler\\ext\\standard\\CopyJitHelper::copyArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COPY_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_copy',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_copy');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_copy', self::implementCopyBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');

        // getNamedFunction first — leftover Type always-on addFunction without it
        // minted __compiler_copy.1 on ABI drift (#32466 / #32122).
        return $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr, $strPtr)
        );
    }

    private static function implementCopyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('copy_bridge_entry');
        $fail = $fn->appendBasicBlock('copy_bridge_fail');
        $body = $fn->appendBasicBlock('copy_bridge_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $to, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $ok = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COPY_HELPER),
            [$from, $to]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $ok, $i32)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CopyJitHelper compile (#9585)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22231'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after CopyRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
