<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __superglobals__refresh via SuperglobalRefreshJitHelper PHP (#9907).
 *
 * MCJIT embed: {@see \PHPCompiler\JIT\SuperglobalInit::implementRefresh} copies VM tables.
 * Standalone LLVM quarantine: {@see SuperglobalRefreshStandaloneLlvm}
 * SSOT: {@see \PHPCompiler\Web\Superglobals}
 * php-src: main/php_variables.c
 */
final class SuperglobalRefreshRuntime
{
    private const HELPER_PATH = '/lib/Web/SuperglobalRefreshJitHelper.php';

    private const BUILD_GET = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildGetTable';

    private const BUILD_POST = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildPostTable';

    private const BUILD_FILES = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildFilesTable';

    private const BUILD_REQUEST = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildRequestTable';

    private const BUILD_SERVER = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildServerTable';

    private const BUILD_COOKIE = 'PHPCompiler\\Web\\SuperglobalRefreshJitHelper::buildCookieTable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BUILD_GET,
        self::BUILD_POST,
        self::BUILD_FILES,
        self::BUILD_REQUEST,
        self::BUILD_SERVER,
        self::BUILD_COOKIE,
    ];

    /** @var list<array{0: string, 1: string}> */
    private const REFRESH_STORES = [
        ['sg_GET', self::BUILD_GET],
        ['sg_POST', self::BUILD_POST],
        ['sg_FILES', self::BUILD_FILES],
        ['sg_REQUEST', self::BUILD_REQUEST],
        ['sg_SERVER', self::BUILD_SERVER],
        ['sg_COOKIE', self::BUILD_COOKIE],
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
        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_EMBED === $context->loadType) {
            return;
        }

        if (self::useStandaloneLlvmFallback($context)) {
            SuperglobalRefreshStandaloneLlvm::implement($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureHeaderQueueExternal($context);
        self::ensureJitHelperCompiled($context);

        $fn = self::declareRefresh($context);
        self::implementRefreshBridge($context, $fn);

        self::restoreInsertBlock($context, $restore);
        self::registerLinkedRuntime($context);
    }

    private static function useStandaloneLlvmFallback(Context $context): bool
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $phpBridge = getenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_PHP');
            if ('0' === $phpBridge || 'false' === strtolower((string) $phpBridge)) {
                return true;
            }

            return false;
        }

        foreach (['PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM', 'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER'] as $key) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }

    private static function declareRefresh(Context $context): LlvmFunction
    {
        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe) {
            return $probe;
        }

        $fn = $context->module->addFunction(
            '__superglobals__refresh',
            $context->context->functionType($context->context->voidType(), false)
        );
        $context->registerFunction('__superglobals__refresh', $fn);

        return $fn;
    }

    private static function implementRefreshBridge(Context $context, LlvmFunction $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('sg_refresh_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $context->builder->call($context->lookupFunction('__phpc_header_queue_enable'));

        foreach (self::REFRESH_STORES as [$globalName, $helperLogical]) {
            $htRaw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, $helperLogical),
                []
            );
            $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
            $context->builder->store($ht, self::sgGlobalPtr($context, $globalName));
        }

        foreach (['sg_ENV', 'sg_SESSION'] as $globalName) {
            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            $context->builder->store($ht, self::sgGlobalPtr($context, $globalName));
        }

        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureGlobals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        foreach (['sg_GET', 'sg_POST', 'sg_REQUEST', 'sg_SERVER', 'sg_COOKIE', 'sg_ENV', 'sg_FILES', 'sg_SESSION'] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($htPtr, $name);
                $g->setInitializer($htPtr->constNull());
            }
        }
    }

    private static function ensureHeaderQueueExternal(Context $context): void
    {
        try {
            $context->lookupFunction('__phpc_header_queue_enable');
        } catch (\Throwable) {
            PendingHeadersRuntime::ensureLinked($context);
        }
    }

    private static function sgGlobalPtr(Context $context, string $name): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('SuperglobalRefreshRuntime global missing: '.$name);
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->builder->pointerCast($global, $htPtr->pointerType(0));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SuperglobalRefreshJitHelper compile (#9907)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SuperglobalRefreshJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SuperglobalRefreshJitHelper.php parseAndCompile failed (#9907)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT superglobal refresh (#9907)');
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__superglobals__refresh');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__superglobals__refresh missing after SuperglobalRefreshRuntime bridge (#9907)');
        }
        $context->registerFunction('__superglobals__refresh', $fn);
    }
}
