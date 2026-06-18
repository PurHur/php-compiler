<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_gc_enable/disable/is_enabled via GcToggleJitHelper PHP (#9577).
 *
 * JIT embed uses compiled {@see GcToggleJitHelper}; AOT standalone keeps {@see GcToggleStandaloneLlvm}
 * until compiled PHP static storage is reliable in native link (same pattern as #9454 LastError).
 * php-src: ext/standard/php_gc.c
 */
final class GcToggleRuntime
{
    private const HELPER_PATH = '/ext/standard/GcToggleJitHelper.php';

    private const ENABLE_HELPER = 'PHPCompiler\\ext\\standard\\GcToggleJitHelper::enable';

    private const DISABLE_HELPER = 'PHPCompiler\\ext\\standard\\GcToggleJitHelper::disable';

    private const IS_ENABLED_HELPER = 'PHPCompiler\\ext\\standard\\GcToggleJitHelper::isEnabled';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENABLE_HELPER,
        self::DISABLE_HELPER,
        self::IS_ENABLED_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_gc_enable',
        'phpc_gc_disable',
        'phpc_gc_is_enabled',
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
        $probe = $context->module->getNamedFunction('phpc_gc_is_enabled');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            GcToggleStandaloneLlvm::implement($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementVoidBridge($context, 'phpc_gc_enable', self::ENABLE_HELPER);
        self::implementVoidBridge($context, 'phpc_gc_disable', self::DISABLE_HELPER);
        self::implementIsEnabledBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementIsEnabledBridge(Context $context): void
    {
        $abiName = 'phpc_gc_is_enabled';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gc_is_enabled_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $enabled = $context->builder->call(self::helperFunction($context, self::IS_ENABLED_HELPER));
        $context->builder->returnValue($context->builder->zext($enabled, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gc_toggle_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcToggleJitHelper compile (#9577)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcToggleJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcToggleJitHelper.php parseAndCompile failed (#9577)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
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
                throw new \LogicException($lc.' was not compiled for JIT (#9577)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after GcToggleRuntime bridge (#9577)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
