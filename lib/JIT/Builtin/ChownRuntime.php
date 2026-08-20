<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
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

    /** @return Value i1 — true when chown/lchown succeeds */
    public static function invokeChown(Context $context, Value $pathStr, Value $userVal, bool $lchown): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedChx($context, $pathStr, $userVal, $lchown, false);
        }

        // Thin user-script AOT: libc leaf directly (bridge→ChownJitHelper SIGABRT on int uid; #32466).
        return self::invokeNestedChx($context, $pathStr, $userVal, $lchown, false);
    }

    /** @return Value i1 — true when chgrp/lchgrp succeeds */
    public static function invokeChgrp(Context $context, Value $pathStr, Value $groupVal, bool $lchgrp): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedChx($context, $pathStr, $groupVal, $lchgrp, true);
        }

        return self::invokeNestedChx($context, $pathStr, $groupVal, $lchgrp, true);
    }

    /** @return Value i1 — libc chown/lchown/chgrp/lchgrp leaf during NestedJIT (#32466). */
    private static function invokeNestedChx(
        Context $context,
        Value $pathStr,
        Value $idVal,
        bool $linkOnly,
        bool $isGrp
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $pathCstr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $uidI64 = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $idVal
        );
        $id32 = $context->builder->trunc($uidI64, $i32);

        if ($isGrp) {
            // Linux glibc has no `chgrp` symbol — PHP uses fchownat(AT_FDCWD, path, -1, gid, …).
            $minusOne = $i32->constInt(-1, true);
            $atFdcwd = $i32->constInt(-100, true);
            $flags = $i32->constInt($linkOnly ? 0x100 : 0, false);
            $ret = $context->builder->call(
                self::ensureLibcFchownatDecl($context),
                $atFdcwd,
                $pathCstr,
                $minusOne,
                $id32,
                $flags
            );
        } elseif ($linkOnly) {
            $ret = $context->builder->call(
                self::ensureLibcChxDecl($context, 'lchown'),
                $pathCstr,
                $id32
            );
        } else {
            $ret = $context->builder->call(
                self::ensureLibcChxDecl($context, 'chown'),
                $pathCstr,
                $id32
            );
        }

        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    private static function ensureLibcFchownatDecl(Context $context): LlvmFunction
    {
        try {
            return $context->lookupFunction('fchownat');
        } catch (\Throwable) {
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i32, $i8p, $i32, $i32, $i32);
        $fn = $context->module->addFunction('fchownat', $ft);
        $context->registerFunction('fchownat', $fn);

        return $fn;
    }

    private static function ensureLibcChxDecl(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
        }

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i32);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
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
