<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\UndefinedPropertyFetchJitHelper;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for undefined dynamic property read warnings (#15752).
 *
 * SSOT: {@see UndefinedPropertyFetchJitHelper}, {@see ErrorReporter}
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
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::emitStandaloneWarning($context, $className, $propertyName);

            return;
        }

        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::EMIT_WARNING_HELPER),
            $context->constantFromString($className),
            $context->constantFromString($propertyName)
        );
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UndefinedPropertyFetchJitHelper compile (#15752)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'UndefinedPropertyFetchJitHelper.php');
            if (null === $block) {
                throw new \LogicException('UndefinedPropertyFetchJitHelper.php parseAndCompile failed (#15752)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#15752)');
            }
        }
    }
}
