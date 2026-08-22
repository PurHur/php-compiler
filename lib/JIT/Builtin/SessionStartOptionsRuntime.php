<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT bridge for session_start($options) (#18457).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\SessionStartOptionsJitHelper}.
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 *
 * Type::register no longer ensureLinked this ABI always-on (#33945); call-site
 * {@see \PHPCompiler\ext\standard\JitSessionStartOptions::invoke} owns the link.
 */
final class SessionStartOptionsRuntime
{
    public const ABI = '__phpc_session_start_options_apply';

    private const HELPER_PATH = '/ext/standard/SessionStartOptionsJitHelper.php';

    private const APPLY_HELPER = 'PHPCompiler\\ext\\standard\\SessionStartOptionsJitHelper::applyAndStart';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::APPLY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // NestedJIT scalar coerce looks up __compiler_trigger_error (#33248 / #33945).
        // Type::register no longer ensureLinked this ABI always-on — link trigger_error
        // here before compiling SessionStartOptionsJitHelper.
        StringTriggerError::ensureLinked($context);

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18457');

        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->context->voidType();
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($void, false, $valuePtr, $valuePtr)
            );

        self::implementBridge($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sso_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::APPLY_HELPER, '#18457');
        JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnVoid();
    }
}
