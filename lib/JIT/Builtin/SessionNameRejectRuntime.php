<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile-time helpers for session_name() rejection warnings (#12563, #25092).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer TimezoneOffset #25042).
 * php-src: ext/session/session.c — php_session_valid_key / session_name validation
 */
final class SessionNameRejectRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionNameJitHelper.php';

    private const IS_REJECTED = 'PHPCompiler\\ext\\standard\\SessionNameJitHelper::isRejected';

    private const WARNING_MESSAGE = 'PHPCompiler\\ext\\standard\\SessionNameJitHelper::warningMessage';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_REJECTED,
        self::WARNING_MESSAGE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function isRejectedFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return self::helperFunction($context, self::IS_REJECTED);
    }

    public static function warningMessageFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return self::helperFunction($context, self::WARNING_MESSAGE);
    }

    public static function emitWarningFromString(Context $context, Value $msgStr): void
    {
        StringTriggerError::ensureLinked($context);
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $strMap['length'])
        );
        $msgBytes = $context->builder->structGep($msgStr, $strMap['value']);
        $msgPtr = $context->builder->pointerCast($msgBytes, $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->trunc($msgLen, $context->getTypeFromString('size_t')),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25092');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25092'
        );
    }
}
