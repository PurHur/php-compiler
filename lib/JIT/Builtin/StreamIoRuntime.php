<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT stream I/O ABI via StreamIoJitHelper PHP (#10326, #12956).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\StreamIoJitHelper}
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class StreamIoRuntime
{
    /** True while Context::ensureFullStandaloneBodies runs — defer nested-JIT helpers (#14472). */
    private static bool $standaloneInitPhase = false;

    public static function beginStandaloneInitPhase(): void
    {
        self::$standaloneInitPhase = true;
    }

    public static function endStandaloneInitPhase(): void
    {
        self::$standaloneInitPhase = false;
    }

    private static int $implementDepth = 0;

    private const HELPER_PATH = '/ext/standard/StreamIoJitHelper.php';

    private const FOPEN = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::fopenArgv';

    private const POPEN = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::popenArgv';

    private const TMPFILE = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::tmpfileArgv';

    private const FREAD = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::freadArgv';

    private const FWRITE = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::fwriteArgv';

    private const SUPPORTS = 'PHPCompiler\\ext\\standard\\StreamIoJitHelper::supportsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FOPEN,
        self::POPEN,
        self::TMPFILE,
        self::FREAD,
        self::FWRITE,
        self::SUPPORTS,
    ];

    /** @var list<string> */
    private const IO_RUNTIME_FUNCTIONS = [
        '__compiler_fwrite',
        '__compiler_fopen',
        '__compiler_popen',
        '__compiler_tmpfile',
        '__compiler_fread',
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        ...self::IO_RUNTIME_FUNCTIONS,
        '__compiler_stream_supports',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /**
     * User-script standalone must link real stream I/O when fopen/tmpfile appear in lowering (#9142, #19462).
     *
     * Inventory init defers heavy emitters; user-script AOT cannot nested-JIT VmFs (#16075) —
     * upgrade via {@see StreamIoStandaloneLlvm} libc + handle-table bridges instead.
     */
    public static function ensureLinkedForUserScriptLowering(Context $context): void
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StreamIoStandaloneLlvm::implementForUserScriptLowering($context);

            return;
        }

        if (self::allIoRuntimeFunctionsLinked($context)) {
            self::registerIoRuntime($context);
            self::ensureSupportsBridgeLinked($context);

            return;
        }

        self::implementBridges($context);
    }

    public static function implement(Context $context): void
    {
        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (self::$implementDepth > 0) {
            return;
        }

        ++self::$implementDepth;
        try {
            self::implementBridges($context);
        } finally {
            --self::$implementDepth;
        }
    }

    private static function implementBridges(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        self::ensureRuntimeAbiDeclared($context);
        self::ensureJitHelperCompiled($context);
        self::implementFwriteBridge($context);
        self::implementBinaryStringBridge($context, '__compiler_fopen', self::FOPEN);
        self::implementBinaryStringBridge($context, '__compiler_popen', self::POPEN);
        self::implementNullaryI64Bridge($context, '__compiler_tmpfile', self::TMPFILE);
        self::implementNullableStringBridge($context, '__compiler_fread', self::FREAD, 2);
        self::implementSupportsBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            if (!self::isRealBridgeLinked($context, $name)) {
                return false;
            }
        }

        return true;
    }

    private static function allIoRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::IO_RUNTIME_FUNCTIONS as $name) {
            if (!self::isRealBridgeLinked($context, $name)) {
                return false;
            }
        }

        return true;
    }

    /** True when $name has a real bridge body (not inventory defer stub or empty declare). */
    public static function isStreamIoBridgeLinked(Context $context, string $name): bool
    {
        return self::isRealBridgeLinked($context, $name);
    }

    /** Inventory defer stubs use a single `entry` block — user-script AOT must upgrade (#19462). */
    public static function isDeferStub(LlvmFunction $fn): bool
    {
        if (1 !== $fn->countBasicBlocks()) {
            return false;
        }
        foreach ($fn->getBasicBlocks() as $block) {
            return 'entry' === $block->getName();
        }

        return false;
    }

    private static function isRealBridgeLinked(Context $context, string $name): bool
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            return false;
        }

        return !self::isDeferStub($fn);
    }

    private static function clearDeferStub(LlvmFunction $fn): void
    {
        if (!self::isDeferStub($fn)) {
            return;
        }
        foreach (array_reverse($fn->getBasicBlocks()) as $block) {
            $block->delete();
        }
    }

    /** @return bool true when the ABI already has a real (non-defer) bridge */
    private static function skipIfRealBridgeLinked(Context $context, string $abiName): bool
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }
        if (self::isDeferStub($probe)) {
            self::clearDeferStub($probe);

            return false;
        }
        $context->registerFunction($abiName, $probe);

        return true;
    }

    private static function implementNullaryI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        if (self::skipIfRealBridgeLinked($context, $abiName)) {
            return;
        }

        $probe = $context->module->getNamedFunction($abiName);

        $i64 = $context->getTypeFromString('int64');
        $fn = $probe ?? $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false)
        );

        $entry = $fn->appendBasicBlock('stream_io_nullary_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBinaryStringBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        if (self::skipIfRealBridgeLinked($context, $abiName)) {
            return;
        }

        $probe = $context->module->getNamedFunction($abiName);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $probe ?? $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false, $strPtr, $strPtr)
        );

        $entry = $fn->appendBasicBlock('stream_io_binstr_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_binstr_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_binstr_bridge_body');
        $context->builder->positionAtEnd($entry);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $left, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $right, $strPtr->constNull())
        );
        $context->builder->branchIf($badArgs, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$left, $right]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFwriteBridge(Context $context): void
    {
        $abiName = '__compiler_fwrite';
        if (self::skipIfRealBridgeLinked($context, $abiName)) {
            return;
        }

        $probe = $context->module->getNamedFunction($abiName);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = $probe ?? $context->module->addFunction(
            $abiName,
            $context->context->functionType($i64, false, $i64, $strPtr, $i64)
        );

        $entry = $fn->appendBasicBlock('stream_io_fwrite_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_fwrite_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_fwrite_bridge_body');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(1);
        $dataNull = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $context->builder->branchIf($dataNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FWRITE),
            [
                $context->builder->trunc($fn->getParam(0), $i32),
                $data,
                $context->builder->trunc($fn->getParam(2), $i32),
            ]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementNullableStringBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $i64ArgCount
    ): void {
        if (self::skipIfRealBridgeLinked($context, $abiName)) {
            return;
        }

        $probe = $context->module->getNamedFunction($abiName);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $params = array_fill(0, $i64ArgCount, $i64);
        $fn = $probe ?? $context->module->addFunction(
            $abiName,
            $context->context->functionType($strPtr, false, ...$params)
        );

        $entry = $fn->appendBasicBlock('stream_io_str_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_io_str_bridge_fail');
        $body = $fn->appendBasicBlock('stream_io_str_bridge_body');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $i64ArgCount; ++$i) {
            $args[] = $context->builder->trunc($fn->getParam($i), $i32);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            $args
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($failed, $fail, $body);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($body);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    /** Link __compiler_stream_supports via StreamIoJitHelper — shares fopen/tmpfile VmFs table (#19462). */
    public static function ensureSupportsBridgeLinked(Context $context): void
    {
        self::implementSupportsBridge($context);
    }

    private static function isLegacyStreamCapsSupportsBridge(LlvmFunction $fn): bool
    {
        if (0 === $fn->countBasicBlocks()) {
            return false;
        }
        foreach ($fn->getBasicBlocks() as $block) {
            if ('stream_supports_bridge_entry' === $block->getName()) {
                return true;
            }
        }

        return false;
    }

    private static function isStreamIoSupportsBridge(LlvmFunction $fn): bool
    {
        if (0 === $fn->countBasicBlocks()) {
            return false;
        }
        foreach ($fn->getBasicBlocks() as $block) {
            if ('stream_io_supports_bridge_entry' === $block->getName()) {
                return true;
            }
        }

        return false;
    }

    private static function clearFunctionBody(LlvmFunction $fn): void
    {
        foreach (array_reverse($fn->getBasicBlocks()) as $block) {
            $block->delete();
        }
    }

    private static function implementSupportsBridge(Context $context): void
    {
        $abiName = '__compiler_stream_supports';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && self::isStreamIoSupportsBridge($probe)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        if (null !== $probe && 0 !== $probe->countBasicBlocks()) {
            if (self::isDeferStub($probe) || self::isLegacyStreamCapsSupportsBridge($probe)) {
                self::clearFunctionBody($probe);
            } else {
                if (null !== $savedBlock) {
                    BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
                }

                return;
            }
        }

        $probe = $context->module->getNamedFunction($abiName);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_io_supports_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handleI32 = $context->builder->trunc($fn->getParam(0), $i32);
        $featureI32 = $context->builder->trunc($fn->getParam(1), $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SUPPORTS),
            [$handleI32, $featureI32]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        }
    }

    /** @return LlvmFunction compiled StreamIoJitHelper method for direct JIT calls (#19462). */
    public static function lookupStreamIoHelper(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return self::helperFunction($context, $logical);
    }

    public static function supportsHelperLogical(): string
    {
        return self::SUPPORTS;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamIoJitHelper compile (#10326)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $deferUserScript = UserScriptAotDeferNestedJit::shouldDefer($context);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $deferUserScript): void {
            $prevUser = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            $prevSelf = getenv('PHP_COMPILER_SELFHOST_AOT');
            if ($deferUserScript && \function_exists('putenv')) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
                putenv('PHP_COMPILER_SELFHOST_AOT=0');
                $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '0';
                $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = '0';
            }
            try {
                LibcExtern::register($context);
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamIoJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('StreamIoJitHelper.php parseAndCompile failed (#10326)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            } finally {
                if ($deferUserScript && \function_exists('putenv')) {
                    if (false === $prevUser || '' === (string) $prevUser) {
                        putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                        unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
                    } else {
                        putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prevUser);
                        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUser;
                        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prevUser;
                    }
                    if (false === $prevSelf || '' === (string) $prevSelf) {
                        putenv('PHP_COMPILER_SELFHOST_AOT=');
                        unset($_ENV['PHP_COMPILER_SELFHOST_AOT'], $_SERVER['PHP_COMPILER_SELFHOST_AOT']);
                    } else {
                        putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelf);
                        $_ENV['PHP_COMPILER_SELFHOST_AOT'] = $prevSelf;
                        $_SERVER['PHP_COMPILER_SELFHOST_AOT'] = $prevSelf;
                    }
                }
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT stream I/O (#10326)');
            }
        }
    }

    private static function registerIoRuntime(Context $context): void
    {
        foreach (self::IO_RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamIoRuntime bridge (#10326)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StreamIoRuntime bridge (#10326)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    public static function shouldDeferHeavyStreamIoEmitters(Context $context): bool
    {
        unset($context);
        if (self::$standaloneInitPhase) {
            return true;
        }
        foreach (
            [
                'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER',
                'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER',
                'PHP_COMPILER_BOOTSTRAP_AOT_LINK',
            ] as $key
        ) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return true;
        }

        return false;
    }

    public static function implementDeferredStreamIoStubs(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();

        self::implementNullaryI64Stub($context, '__compiler_tmpfile', $minusOne);
        self::implementFwriteStub($context, '__compiler_fwrite', $minusOne);
        self::implementBinaryI64Stub($context, '__compiler_fopen', $minusOne);
        self::implementBinaryI64Stub($context, '__compiler_popen', $minusOne);
        self::implementBinaryStrStub($context, '__compiler_fread', $nullStr);
        self::implementSupportsStub($context);
    }

    private static function implementSupportsStub(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        self::implementStub(
            $context,
            '__compiler_stream_supports',
            $context->context->functionType($i32, false, $i64, $i64),
            $i32->constInt(0, false)
        );
    }

    private static function implementNullaryI64Stub(Context $context, string $name, Value $ret): void
    {
        self::implementStub($context, $name, $context->context->functionType($context->getTypeFromString('int64'), false), $ret);
    }

    private static function implementBinaryI64Stub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementStub(
            $context,
            $name,
            $context->context->functionType($i64, false, $strPtr, $strPtr),
            $ret
        );
    }

    private static function implementFwriteStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementStub(
            $context,
            $name,
            $context->context->functionType($i64, false, $i64, $strPtr, $i64),
            $ret
        );
    }

    private static function implementBinaryStrStub(Context $context, string $name, Value $ret): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::implementStub(
            $context,
            $name,
            $context->context->functionType($strPtr, false, $i64, $i64),
            $ret
        );
    }

    /** Forward-declare stream I/O ABI for nested helper compile (#13000). */
    private static function ensureRuntimeAbiDeclared(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        self::declareRuntimeFn($context, '__compiler_fwrite', $i64, false, $i64, $strPtr, $i64);
        self::declareRuntimeFn($context, '__compiler_fopen', $i64, false, $strPtr, $strPtr);
        self::declareRuntimeFn($context, '__compiler_popen', $i64, false, $strPtr, $strPtr);
        self::declareRuntimeFn($context, '__compiler_tmpfile', $i64, false);
        self::declareRuntimeFn($context, '__compiler_fread', $strPtr, false, $i64, $i64);
        self::declareRuntimeFn($context, '__compiler_stream_supports', $context->getTypeFromString('int32'), false, $i64, $i64);
    }

    private static function declareRuntimeFn(Context $context, string $name, $ret, bool $vararg, ...$params): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        $ft = $context->context->functionType($ret, $vararg, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function implementStub(Context $context, string $name, $ft, Value $ret): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        if (null === $probe) {
            try {
                $probe = $context->lookupFunction($name);
            } catch (\Throwable) {
                $probe = null;
            }
        }
        $fn = $probe ?? $context->module->addFunction($name, $ft);
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($ret);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }
}
