<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_stream_mode via StreamModeJitHelper PHP (#13021, #19794, #22968).
 *
 * Quarantined from lib/JIT/Builtin/StreamModeRuntime — {@see \PHPCompiler\JIT\Builtin\StreamModeRuntime}
 * stays the thin orchestrator. Helper compile: {@see JitVmHelperLink::ensureCompiled}
 * (peer StreamFilter #21041).
 *
 * SSOT: {@see StreamModeJitHelper}, {@see VmFs}, {@see VmStreamMeta}
 * php-src: main/streams/streams.c — php_stream_get_meta_data mode field
 */
final class JitStreamModeKernel
{
    private const HELPER_PATH = '/ext/standard/StreamModeJitHelper.php';

    private const MODE_HELPER = 'PHPCompiler\\ext\\standard\\StreamModeJitHelper::modeForHandle';

    private const REGISTER_HELPER = 'PHPCompiler\\ext\\standard\\StreamModeJitHelper::register';

    private const CLEAR_HELPER = 'PHPCompiler\\ext\\standard\\StreamModeJitHelper::clear';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MODE_HELPER,
        self::REGISTER_HELPER,
        self::CLEAR_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_stream_mode',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_mode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        self::ensureJitHelperCompiled($context);
        self::implementModeBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function emitRegisterMode(Context $context, Value $handle, Value $modeStr): void
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::REGISTER_HELPER),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64')),
            $modeStr
        );
    }

    public static function emitClearMode(Context $context, Value $handle): void
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::CLEAR_HELPER),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64'))
        );
    }

    private static function implementModeBridge(Context $context): void
    {
        $abiName = '__phpc_stream_mode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stream_mode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::MODE_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22968');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22968'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitStreamModeKernel bridge (#13021)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
