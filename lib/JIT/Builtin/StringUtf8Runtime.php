<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_utf8_strlen / __compiler_utf8_valid via Utf8JitHelper PHP (#9246, #9273).
 *
 * Replaces former StringUtf8StrlenJit / StringUtf8ValidJit LLVM. SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/utf8.c, ext/mbstring/mbstring.c
 */
final class StringUtf8Runtime
{
    private const HELPER_PATH = '/ext/standard/Utf8JitHelper.php';

    private const STRLEN_HELPER = 'PHPCompiler\\ext\\standard\\Utf8JitHelper::utf8CharLength';

    private const VALID_HELPER = 'PHPCompiler\\ext\\standard\\Utf8JitHelper::isValidUtf8';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRLEN_HELPER,
        self::VALID_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_utf8_strlen',
        '__compiler_utf8_valid',
    ];

    public static function ensureLinked(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        self::implement($context);
        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function ensureStrlenLinked(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureValidLinked(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function validFromPtr(Context $context, Value $strPtr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_valid'),
            $strPtr
        );
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_utf8_strlen');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context, '__compiler_utf8_strlen', self::STRLEN_HELPER, 0);
        self::implementBridge($context, '__compiler_utf8_valid', self::VALID_HELPER, 1);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $nullReturn
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('utf8_bridge_entry');
        $nullBb = $fn->appendBasicBlock('utf8_bridge_null');
        $workBb = $fn->appendBasicBlock('utf8_bridge_work');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $nullStr = $strPtr->constNull();
        $nullRet = $i64->constInt($nullReturn, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $input, $nullStr);
        $context->builder->branchIf($isNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullRet);

        $context->builder->positionAtEnd($workBb);
        $result = $context->builder->call(self::helperFunction($context, $helperLogical), $input);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Utf8JitHelper compile (#9273)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Utf8JitHelper.php');
            if (null === $block) {
                throw new \LogicException('Utf8JitHelper.php parseAndCompile failed (#9273)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9273)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUtf8Runtime bridge (#9273)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
