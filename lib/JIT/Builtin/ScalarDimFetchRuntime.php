<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for scalar dim fetch warnings via ScalarDimFetchJitHelper PHP (#10271, #10343).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchRuntime
{
    private const ABI_EMIT_WARNING = '__scalar_dim_fetch__emitWarning';

    private const HELPER_PATH = '/lib/VM/ScalarDimFetchJitHelper.php';

    private const EMIT_WARNING_HELPER = 'PHPCompiler\\VM\\ScalarDimFetchJitHelper::emitWarningForJitType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EMIT_WARNING_HELPER,
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
        $probe = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EMIT_WARNING,
            'scalar_dim_fetch_warn_bridge_entry',
            [$i8],
            $voidTy,
            self::EMIT_WARNING_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10343'
        );
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitWarning(Context $context, int $jitType): void
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EMIT_WARNING);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->call(
            $fn,
            $i8->constInt($jitType, false)
        );
    }

    /**
     * Runtime bool dim-read: select true/false synthetic type codes (#30053).
     */
    public static function emitWarningForNativeBool(Context $context, \PHPCompiler\JIT\Variable $boolVar): void
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EMIT_WARNING);
        $i8 = $context->getTypeFromString('int8');
        $loaded = $context->helper->loadValue($boolVar);
        $trueCode = $i8->constInt(\PHPCompiler\VM\ScalarDimFetchJitHelper::JIT_BOOL_TRUE, false);
        $falseCode = $i8->constInt(\PHPCompiler\VM\ScalarDimFetchJitHelper::JIT_BOOL_FALSE, false);
        $code = $context->builder->select($loaded, $trueCode, $falseCode);
        $context->builder->call($fn, $code);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_EMIT_WARNING);
        if (null !== $fn) {
            $context->registerFunction(self::ABI_EMIT_WARNING, $fn);
        }
    }
}
