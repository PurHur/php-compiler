<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_timezone_offset_seconds via TimezoneOffsetJitHelper PHP (#9452).
 *
 * Replaces setenv/localtime_r/timegm LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDateTimeNative}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_offset_get)
 */
final class TimezoneOffsetRuntime
{
    private const ABI_NAME = '__phpc_timezone_offset_seconds';

    private const HELPER_PATH = '/ext/standard/TimezoneOffsetJitHelper.php';

    private const OFFSET_HELPER = 'PHPCompiler\\ext\\standard\\TimezoneOffsetJitHelper::offsetSeconds';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::OFFSET_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternals($context);
        self::ensureJitHelperCompiled($context);
        self::implementOffsetBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementOffsetBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('tzoff_bridge_entry');
        $nullBb = $fn->appendBasicBlock('tzoff_bridge_null');
        $bodyBb = $fn->appendBasicBlock('tzoff_bridge_body');
        $context->builder->positionAtEnd($entry);

        $tzStr = $fn->getParam(0);
        $timestamp = $fn->getParam(1);
        $out = $fn->getParam(2);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullTz = $context->builder->icmp(Builder::INT_EQ, $tzStr, $strPtr->constNull());
        $context->builder->branchIf(
            $context->builder->or($nullOut, $nullTz),
            $nullBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $offset = $context->builder->call(
            self::helperFunction($context, self::OFFSET_HELPER),
            $tzStr,
            $timestamp
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $offset
        );
        $context->builder->returnVoid();
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after TimezoneOffsetJitHelper compile (#9452)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'TimezoneOffsetJitHelper.php');
            if (null === $block) {
                throw new \LogicException('TimezoneOffsetJitHelper.php parseAndCompile failed (#9452)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9452)');
            }
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');

        $name = '__value__writeLong';
        if (null === $context->module->getNamedFunction($name)) {
            $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $valuePtr, $i64)
            );
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after TimezoneOffsetRuntime bridge (#9452)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
