<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for stream capability ABI via StreamCapsJitHelper PHP (#11413, #19772, #23012).
 *
 * Quarantined from lib/JIT/Builtin/StreamCapsRuntime — {@see \PHPCompiler\JIT\Builtin\StreamCapsRuntime}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamSync #23004 / StreamMeta #22994 / StreamBuffer #22979 / StreamMode #22968).
 *
 * SSOT: {@see VmFs}, {@see VmStreamMeta}, {@see StreamCapsJitHelper}
 * php-src: ext/standard/streamsfuncs.c
 */
final class JitStreamCapsKernel
{
    private const HELPER_PATH = '/ext/standard/StreamCapsJitHelper.php';

    private const IS_LOCAL_URI_HELPER = 'PHPCompiler\\ext\\standard\\StreamCapsJitHelper::isLocalUriArgv';

    private const ISATTY_HELPER = 'PHPCompiler\\ext\\standard\\StreamCapsJitHelper::isattyArgv';

    private const IS_LOCAL_HELPER = 'PHPCompiler\\ext\\standard\\StreamCapsJitHelper::isLocalArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_LOCAL_URI_HELPER,
        self::ISATTY_HELPER,
        self::IS_LOCAL_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_stream_is_local_uri',
        '__compiler_stream_isatty',
        '__compiler_stream_is_local',
        '__compiler_stream_supports',
    ];

    /** @var array<string, string> */
    private const SINGLE_ARG_ABI_TO_HELPER = [
        '__compiler_stream_isatty' => self::ISATTY_HELPER,
        '__compiler_stream_is_local' => self::IS_LOCAL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureLocalUriLinked(Context $context): void
    {
        if (self::isLocalUriLinked($context)) {
            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureExternStringInit($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_is_local_uri', self::implementIsLocalUriBridge(...));

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function implement(Context $context): void
    {
        StreamIoRuntime::ensureSupportsBridgeLinked($context);

        if (self::allRuntimeFunctionsLinked($context)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureExternStringInit($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_stream_is_local_uri', self::implementIsLocalUriBridge(...));
        foreach (self::SINGLE_ARG_ABI_TO_HELPER as $abi => $helper) {
            self::implementSingleArgBridge($context, $abi, $helper);
        }
        StreamIoRuntime::ensureSupportsBridgeLinked($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function isLocalUriLinked(Context $context): bool
    {
        $fn = $context->module->getNamedFunction('__compiler_stream_is_local_uri');

        return null !== $fn && $fn->countBasicBlocks() > 0;
    }

    private static function allRuntimeFunctionsLinked(Context $context): bool
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                return false;
            }
            if ('__compiler_stream_supports' === $name && StreamIoRuntime::isDeferStub($fn)) {
                return false;
            }
        }

        return true;
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
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = match ($name) {
            '__compiler_stream_supports' => $context->context->functionType($i32, false, $i64, $i64),
            '__compiler_stream_is_local_uri' => $context->context->functionType($i32, false, $i8p),
            default => $context->context->functionType($i32, false, $i64),
        };
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function implementIsLocalUriBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_is_local_uri_bridge_entry');
        $fail = $fn->appendBasicBlock('stream_is_local_uri_bridge_fail');
        $body = $fn->appendBasicBlock('stream_is_local_uri_bridge_body');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $path = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $i8p->constNull());
        $context->builder->branchIf($isNull, $fail, $body);

        $context->builder->positionAtEnd($body);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $path);
        $uriObj = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zext($len, $i64),
            $path
        );
        $hitRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::IS_LOCAL_URI_HELPER),
            [$uriObj]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $hitRaw, $i32)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function implementSingleArgBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_caps_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handleI32 = $context->builder->trunc($fn->getParam(0), $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$handleI32]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23012');
    }

    private static function ensureExternStringInit(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($sizeT, false, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
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

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23012'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitStreamCapsKernel bridge (#11413)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
