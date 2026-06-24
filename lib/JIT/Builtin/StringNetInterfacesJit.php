<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_net_get_interfaces via NetInterfacesJitHelper PHP (#8988).
 *
 * Replaces the former getifaddrs LLVM walk monolith.
 * SSOT: VmNetInterfaces::get. php-src: ext/standard/net.c
 */
final class StringNetInterfacesJit
{
    private const ABI_NAME = '__compiler_net_get_interfaces';

    private const HELPER_PATH = '/ext/standard/NetInterfacesJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::resolve';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
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
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('ngi_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('ngi_bridge_null_out');
        $bodyBb = $fn->appendBasicBlock('ngi_bridge_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($outNull, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $htObj = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_HELPER)
        );
        $ht = $htObj->typeOf() === $htPtr
            ? $htObj
            : $context->builder->pointerCast($htObj, $htPtr);
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $failBb = $fn->appendBasicBlock('ngi_bridge_fail');
        $okBb = $fn->appendBasicBlock('ngi_bridge_ok');
        $context->builder->branchIf($htNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after NetInterfacesJitHelper compile (#8988)');
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
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'NetInterfacesJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('NetInterfacesJitHelper.php parseAndCompile failed (#8988)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#8988)');
            }
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');

        foreach ([
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after StringNetInterfacesJit bridge (#8988)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
