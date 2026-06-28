<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_ip2long/long2ip/inet_* via InetJitHelper PHP (#8969).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc inet LLVM (#13193).
 * SSOT: {@see \PHPCompiler\ext\standard\VmInet}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ip2long), long2ip, inet_ntop, inet_pton
 */
final class InetRuntime
{
    private const HELPER_PATH = '/ext/standard/InetJitHelper.php';

    private const IP2LONG_TAG = 'PHPCompiler\\ext\\standard\\InetJitHelper::ip2longTag';

    private const LONG2IP_TAG = 'PHPCompiler\\ext\\standard\\InetJitHelper::long2ipTag';

    private const LAST_INT = 'PHPCompiler\\ext\\standard\\InetJitHelper::lastInt';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\InetJitHelper::lastString';

    private const INET_PTON = 'PHPCompiler\\ext\\standard\\InetJitHelper::inetPton';

    private const INET_NTOP = 'PHPCompiler\\ext\\standard\\InetJitHelper::inetNtop';

    private const TAG_FALSE = 0;

    private const TAG_INT = 1;

    private const TAG_STRING = 2;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IP2LONG_TAG,
        self::LONG2IP_TAG,
        self::LAST_INT,
        self::LAST_STRING,
        self::INET_PTON,
        self::INET_NTOP,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_ip2long',
        '__compiler_long2ip',
        '__compiler_inet_pton',
        '__compiler_inet_ntop',
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
        $probe = $context->module->getNamedFunction('__compiler_ip2long');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_ip2long', self::implementIp2longBridge(...));
        self::implementIfMissing($context, '__compiler_long2ip', self::implementLong2ipBridge(...));
        self::implementIfMissing($context, '__compiler_inet_pton', self::implementInetPtonBridge(...));
        self::implementIfMissing($context, '__compiler_inet_ntop', self::implementInetNtopBridge(...));
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
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

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        return match ($name) {
            '__compiler_ip2long' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $valuePtr, $strPtr)
            ),
            '__compiler_long2ip' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $valuePtr, $i64)
            ),
            '__compiler_inet_pton', '__compiler_inet_ntop' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            default => throw new \LogicException('Unknown inet JIT helper: '.$name),
        };
    }

    private static function implementIp2longBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_ip2long_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $ip = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::IP2LONG_TAG),
            [$ip]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = BasicBlockHelper::append($context, 'inet_ip2long_false');
        $intBb = BasicBlockHelper::append($context, 'inet_ip2long_int');
        $doneBb = BasicBlockHelper::append($context, 'inet_ip2long_done');

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $intBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($intBb);
        $intResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_INT),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($context->builder->trunc($intResult, $i32), $i64)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementLong2ipBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_long2ip_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $addr = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LONG2IP_TAG),
            [$addr]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = BasicBlockHelper::append($context, 'inet_long2ip_false');
        $stringBb = BasicBlockHelper::append($context, 'inet_long2ip_string');
        $doneBb = BasicBlockHelper::append($context, 'inet_long2ip_done');

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $stringBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_STRING),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strResult)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementInetPtonBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_pton_entry');
        $context->builder->positionAtEnd($entry);

        $addr = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $packedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INET_PTON),
            [$addr]
        );
        $packed = JitNestedHelperCoerce::coerceBridgeResult($context, $packedRaw, $strPtr);
        $context->builder->returnValue($packed);
    }

    private static function implementInetNtopBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('inet_ntop_entry');
        $context->builder->positionAtEnd($entry);

        $inAddr = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $textRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::INET_NTOP),
            [$inAddr]
        );
        $text = JitNestedHelperCoerce::coerceBridgeResult($context, $textRaw, $strPtr);
        $context->builder->returnValue($text);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after InetJitHelper compile (#8969)');
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
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'InetJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('InetJitHelper.php parseAndCompile failed (#8969)');
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
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#8969)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after InetRuntime bridge (#8969)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
