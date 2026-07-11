<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile-time helpers for session_name() rejection warnings (#12563).
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
        $i64 = $context->getTypeFromString('int64');
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SessionNameJitHelper compile (#12563)');
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
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SessionNameJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('SessionNameJitHelper.php parseAndCompile failed (#12563)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
    }
}
