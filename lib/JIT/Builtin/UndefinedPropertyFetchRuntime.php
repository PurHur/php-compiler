<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\UndefinedPropertyFetchJitHelper;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for undefined dynamic property read warnings (#15752, #23174).
 *
 * SSOT: {@see UndefinedPropertyFetchJitHelper}, {@see ErrorReporter}
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringVarDump #23143).
 */
final class UndefinedPropertyFetchRuntime
{
    private const HELPER_PATH = '/lib/VM/UndefinedPropertyFetchJitHelper.php';

    private const EMIT_WARNING_HELPER = 'PHPCompiler\\VM\\UndefinedPropertyFetchJitHelper::emitWarning';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EMIT_WARNING_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function emitWarning(Context $context, string $className, string $propertyName): void
    {
        // Always lower via libc trigger_error — NestedJIT helper + constantStringFromString
        // mid-body corrupts the module (parentless loads) when SoapFault/Exception props
        // pull this path into a large verify (#26511 / #23174).
        self::emitStandaloneWarning($context, $className, $propertyName);
    }

    private static function emitStandaloneWarning(
        Context $context,
        string $className,
        string $propertyName
    ): void {
        StringTriggerError::ensureLinked($context);
        $message = UndefinedPropertyFetchJitHelper::warningMessage($className, $propertyName);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23174');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23174'
        );
    }
}
