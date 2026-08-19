<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_chown/__compiler_chgrp via FsDirJitHelper PHP (#9585).
 *
 * Type always-on leftover dropped (#32466): declareFunction uses getNamedFunction first
 * so a drifted ABI cannot mint __compiler_chown.1 (#31894 / #32122).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer CopyRuntime #22231 / #24473).
 * Replaces {@see StringFsDirJit::emitChown}/{@see StringFsDirJit::emitChgrp} libc LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(chown), PHP_FUNCTION(chgrp)
 */
final class ChownRuntime
{
    private const HELPER_PATH = '/ext/standard/ChownJitHelper.php';

    private const CHOWN_HELPER = 'PHPCompiler\\ext\\standard\\ChownJitHelper::chownArgv';

    private const CHGRP_HELPER = 'PHPCompiler\\ext\\standard\\ChownJitHelper::chgrpArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHOWN_HELPER,
        self::CHGRP_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_chown',
        '__compiler_chgrp',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_chown');
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
        self::implementIfMissing($context, '__compiler_chown', self::CHOWN_HELPER, self::implementChownBridge(...));
        self::implementIfMissing($context, '__compiler_chgrp', self::CHGRP_HELPER, self::implementChgrpBridge(...));
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
    private static function implementIfMissing(Context $context, string $name, string $helperLogical, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn, $helperLogical);
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
        $valuePtr = $context->getTypeFromString('__value__*');

        // getNamedFunction first — leftover Type always-on addFunction without it
        // minted __compiler_chown.1 on ABI drift (#32466 / #32122).
        return $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $strPtr, $valuePtr, $i32)
        );
    }

    private static function implementChownBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        self::implementChxBridge($context, $fn, $helperLogical);
    }

    private static function implementChgrpBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        self::implementChxBridge($context, $fn, $helperLogical);
    }

    private static function implementChxBridge(Context $context, LlvmFunction $fn, string $helperLogical): void
    {
        $entry = $fn->appendBasicBlock('chx_bridge_entry');
        $fail = $fn->appendBasicBlock('chx_bridge_fail');
        $body = $fn->appendBasicBlock('chx_bridge_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $path = $fn->getParam(0);
        $idValue = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $idValue, $valuePtr->constNull())
        );
        $context->builder->branchIf($bad, $fail, $body);

        $context->builder->positionAtEnd($body);
        $ok = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$path, $idValue, $fn->getParam(2)]
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
            throw new \LogicException($logical.' missing after FsDirJitHelper chown/chgrp compile (#9585)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24473'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ChownRuntime bridge (#9585)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
