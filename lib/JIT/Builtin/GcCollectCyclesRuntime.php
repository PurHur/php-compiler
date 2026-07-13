<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM GC registry + cycle collector for JIT/AOT (issues #3160, #5315).
 *
 * Replaces lib/AOT/runtime/phpc_gc.c; semantics mirror Zend gc_collect_cycles subset.
 * php-src: Zend/zend_gc.c
 */
final class GcCollectCyclesRuntime
{
    private const MAX_OBJECTS = 65536;

    private const TYPE_OBJECT = 5;

    private const TYPEINFO_TYPEMASK = 0xFFFFFFFC;

    private const TYPEINFO_TYPE_OBJECT = 8;

    private const G_COUNT = 'phpc_gc_count';

    private const G_RUNS = 'phpc_gc_runs';

    private const G_TOTAL_COLLECTED = 'phpc_gc_total_collected';

    private const G_RUNNING = 'phpc_gc_running';

    private const G_PROTECTED = 'phpc_gc_protected';

    private const G_FULL = 'phpc_gc_full';

    private const G_BUFFER_SIZE = 'phpc_gc_buffer_size';

    private const REGISTRY_HELPER_PATH = '/ext/standard/GcCollectCyclesRegistryJitHelper.php';

    private const DESTRUCT_HELPER_PATH = '/ext/standard/GcDestructAllowDelrefJitHelper.php';

    private const SHUTDOWN_HELPER_PATH = '/ext/standard/GcDestructShutdownJitHelper.php';

    private const TRY_INVOKE_HELPER_PATH = '/ext/standard/GcDestructTryInvokeJitHelper.php';

    private const RELEASE_STORAGE_HELPER_PATH = '/ext/standard/GcObjectReleaseStorageJitHelper.php';

    private const SET_ALLOW_DELREF = 'PHPCompiler\\ext\\standard\\GcDestructAllowDelrefJitHelper::setAllowDelref';

    private const DELREF_ALLOWED = 'PHPCompiler\\ext\\standard\\GcDestructAllowDelrefJitHelper::delrefAllowed';

    private const RUN_SHUTDOWN_DESTRUCTORS = 'PHPCompiler\\ext\\standard\\GcDestructShutdownJitHelper::runShutdownDestructors';

    private const TRY_INVOKE = 'PHPCompiler\\ext\\standard\\GcDestructTryInvokeJitHelper::tryInvoke';

    private const RELEASE_STORAGE = 'PHPCompiler\\ext\\standard\\GcObjectReleaseStorageJitHelper::release';

    /** @var list<string> */
    private const DESTRUCT_COMPILED_HELPERS = [
        self::SET_ALLOW_DELREF,
        self::DELREF_ALLOWED,
    ];

    /** @var list<string> */
    private const SHUTDOWN_COMPILED_HELPERS = [
        self::RUN_SHUTDOWN_DESTRUCTORS,
    ];

    /** @var list<string> */
    private const TRY_INVOKE_COMPILED_HELPERS = [
        self::TRY_INVOKE,
    ];

    /** @var list<string> */
    private const RELEASE_STORAGE_COMPILED_HELPERS = [
        self::RELEASE_STORAGE,
    ];

    private const REG_APPEND = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::appendObject';

    private const REG_REMOVE = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::removeObject';

    private const REG_INDEX_OF = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::indexOf';

    private const REG_COUNT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::count';

    private const REG_OBJECT_PTR = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::objectPtr';

    private const REG_PROP_COUNT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::propCount';

    private const REG_DESTRUCT_INVOKED = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::isDestructInvoked';

    private const REG_MARK_DESTRUCT = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::markDestructInvoked';

    private const REG_DESTRUCT_ALREADY_BY_OBJ = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::destructAlreadyInvokedByObject';

    private const REG_MARK_DESTRUCT_BY_OBJ = 'PHPCompiler\\ext\\standard\\GcCollectCyclesRegistryJitHelper::markDestructInvokedByObject';

    /** @var list<string> */
    private const REGISTRY_COMPILED_HELPERS = [
        self::REG_APPEND,
        self::REG_REMOVE,
        self::REG_INDEX_OF,
        self::REG_COUNT,
        self::REG_OBJECT_PTR,
        self::REG_PROP_COUNT,
        self::REG_DESTRUCT_INVOKED,
        self::REG_MARK_DESTRUCT,
        self::REG_DESTRUCT_ALREADY_BY_OBJ,
        self::REG_MARK_DESTRUCT_BY_OBJ,
    ];

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Declare GC ABI symbols for early JIT link (Refcount delref) without full implement (#10087). */
    public static function ensureDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        self::declareFunction($context, 'phpc_gc_register', $void, [$i8p, $i32]);
        self::declareFunction($context, 'phpc_gc_unregister', $void, [$i8p]);
        self::declareFunction($context, 'phpc_destruct_set_allow_delref', $void, [$i32]);
        self::declareFunction($context, 'phpc_destruct_delref_allowed', $i32, []);
        self::declareFunction($context, 'phpc_destruct_try_invoke', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_notify_object_freed', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_run_shutdown_destructors', $void, []);
        self::declareFunction($context, 'phpc_gc_enable', $void, []);
        self::declareFunction($context, 'phpc_gc_disable', $void, []);
        self::declareFunction($context, 'phpc_gc_is_enabled', $i32, []);
        self::declareFunction($context, '__compiler_gc_collect_cycles', $i64, []);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function declareFunction(
        Context $context,
        string $name,
        \PHPLLVM\Type $returnType,
        array $paramTypes
    ): void {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fnType = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);
    }

    /** LLVM bodies for standalone AOT (replaces phpc_gc.c link — #5315). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_destruct_delref_allowed');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        WeakRefRegistryRuntime::ensureLinked($context);
        GcToggleRuntime::ensureLinked($context);
        if (self::usesPhpRegistry($context)) {
            self::ensureRegistryJitHelperCompiled($context);
            self::ensureShutdownJitHelperCompiled($context);
            self::ensureTryInvokeJitHelperCompiled($context);
            self::ensureReleaseStorageJitHelperCompiled($context);
        }
        self::ensureDestructAllowDelrefJitHelperCompiled($context);
        self::ensureGlobals($context);
        self::ensureExternals($context);
        self::ensureInternalDeclarations($context);

        self::implementAllowDelrefBridge($context);
        self::implementDestructDelrefAllowedBridge($context);
        self::implementGcRegister($context);
        self::implementGcUnregister($context);
        self::implementDestructTryInvoke($context);
        self::implementRunShutdownDestructors($context);
        GcCollectCyclesCollectRuntime::implementCollectBridge($context);

        self::registerLinkedRuntime($context);
    }

    private static function implementAllowDelrefBridge(Context $context): void
    {
        $name = 'phpc_destruct_set_allow_delref';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);

        $entry = $fn->appendBasicBlock('destruct_set_allow_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $allow = $fn->getParam(0);
        $isNonZero = $context->builder->icmp(Builder::INT_NE, $allow, $i32->constInt(0, false));
        $context->builder->call(self::destructHelperFunction($context, self::SET_ALLOW_DELREF), $isNonZero);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
    }

    private static function implementDestructDelrefAllowedBridge(Context $context): void
    {
        $name = 'phpc_destruct_delref_allowed';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($name, $ft);

        $entry = $fn->appendBasicBlock('destruct_delref_allowed_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $allowed = $context->builder->call(self::destructHelperFunction($context, self::DELREF_ALLOWED));
        $context->builder->returnValue($context->builder->zext($allowed, $i32));
        $context->builder->clearInsertionPosition();
        $context->registerFunction($name, $fn);
    }

    private static function implementGcRegister(Context $context): void
    {
        self::implementGcRegisterPhpBridge($context);
    }

    /** @deprecated LLVM registry removed — kept only until dead-code purge completes (#18630). */
    private static function implementGcUnregister(Context $context): void
    {
        self::implementGcUnregisterPhpBridge($context);
    }

    private static function implementDestructTryInvoke(Context $context): void
    {
        self::implementDestructTryInvokePhpBridge($context);
    }

    private static function implementRunShutdownDestructors(Context $context): void
    {
        self::implementRunShutdownDestructorsPhpBridge($context);
    }

    private static function ensureInternalDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidpp = $context->getTypeFromString('void**');

        $internals = [
            'phpc_gc_index_of' => [$i32, false, [$i8p]],
            'phpc_destruct_already_invoked' => [$i32, false, [$i8p]],
            'phpc_destruct_mark_invoked' => [$voidTy, false, [$i8p]],
            'phpc_gc_collect_cycles_impl' => [$i32, false, []],
            'phpc_object_release_storage' => [$voidTy, false, [$i8p]],
        ];
        foreach ($internals as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $context->registerFunction($name, $context->module->addFunction($name, $ft));
        }

        self::implementIndexOf($context);
        self::implementDestructAlreadyInvoked($context);
        self::implementDestructMarkInvoked($context);
        self::implementCollectCyclesImpl($context);
        self::implementObjectReleaseStorage($context);
    }

    private static function implementIndexOf(Context $context): void
    {
        self::implementIndexOfPhpBridge($context);
    }

    private static function implementDestructAlreadyInvoked(Context $context): void
    {
        self::implementDestructAlreadyInvokedPhpBridge($context);
    }

    private static function implementDestructMarkInvoked(Context $context): void
    {
        self::implementDestructMarkInvokedPhpBridge($context);
    }

    private static function implementObjectReleaseStorage(Context $context): void
    {
        self::implementObjectReleaseStoragePhpBridge($context);
    }

    private static function implementCollectCyclesImpl(Context $context): void
    {
        self::implementCollectCyclesPhpBridge($context);
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('GC global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function functionOrCreate(Context $context, string $name, $fnType): LlvmFunction
    {
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing) {
            return $existing;
        }
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        if (null === $context->module->getNamedGlobal(self::G_COUNT)) {
            $g = $context->module->addGlobal($i32, self::G_COUNT);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_RUNS)) {
            $g = $context->module->addGlobal($i32, self::G_RUNS);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_TOTAL_COLLECTED)) {
            $g = $context->module->addGlobal($i32, self::G_TOTAL_COLLECTED);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_RUNNING)) {
            $g = $context->module->addGlobal($i32, self::G_RUNNING);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_PROTECTED)) {
            $g = $context->module->addGlobal($i32, self::G_PROTECTED);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_FULL)) {
            $g = $context->module->addGlobal($i32, self::G_FULL);
            $g->setInitializer($i32->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_BUFFER_SIZE)) {
            $g = $context->module->addGlobal($i32, self::G_BUFFER_SIZE);
            $g->setInitializer($i32->constInt(CycleCollector::DEFAULT_BUFFER_SIZE, false));
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        foreach (
            [
                ['memset', $context->context->functionType($i8p, false, $i8p, $i32, $sizeT)],
                ['__mm__free', $context->context->functionType($voidTy, false, $i8p)],
                ['__value__readObject', $context->context->functionType($objPtr, false, $valuePtr)],
                ['__object__invoke_destructor', $context->context->functionType($voidTy, false, $objPtr)],
            ] as [$name, $ft]
        ) {
            self::ensureExternal($context, $name, $ft);
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                'phpc_gc_register',
                'phpc_gc_unregister',
                'phpc_destruct_set_allow_delref',
                'phpc_destruct_delref_allowed',
                'phpc_destruct_try_invoke',
                'phpc_gc_run_shutdown_destructors',
                'phpc_gc_enable',
                'phpc_gc_disable',
                'phpc_gc_is_enabled',
                '__compiler_gc_collect_cycles',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after GcCollectCyclesRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function usesPhpRegistry(Context $context): bool
    {
        return true;
    }

    private static function ensureDestructAllowDelrefJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::DESTRUCT_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::DESTRUCT_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcDestructAllowDelrefJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcDestructAllowDelrefJitHelper.php parseAndCompile failed (#15852)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::DESTRUCT_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC destruct gate (#15852)');
            }
        }
    }

    private static function destructHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureDestructAllowDelrefJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcDestructAllowDelrefJitHelper compile (#15852)');
        }

        return $fn;
    }

    private static function ensureShutdownJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::SHUTDOWN_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::SHUTDOWN_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcDestructShutdownJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcDestructShutdownJitHelper.php parseAndCompile failed (#15852)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::SHUTDOWN_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC shutdown walk (#15852)');
            }
        }
    }

    private static function shutdownHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureShutdownJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcDestructShutdownJitHelper compile (#15852)');
        }

        return $fn;
    }

    private static function ensureRegistryJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::REGISTRY_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::REGISTRY_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcCollectCyclesRegistryJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcCollectCyclesRegistryJitHelper.php parseAndCompile failed (#9541)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::REGISTRY_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC registry (#9541)');
            }
        }
    }

    private static function registryHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureRegistryJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcCollectCyclesRegistryJitHelper compile (#9541)');
        }

        return $fn;
    }

    private static function syncRegistryCountGlobal(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $count = $context->builder->trunc(
            $context->builder->call(self::registryHelperFunction($context, self::REG_COUNT)),
            $i32
        );
        $context->builder->store($count, self::globalPtr($context, self::G_COUNT, $i32));
    }

    private static function implementGcRegisterPhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = self::functionOrCreate($context, 'phpc_gc_register', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_register_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $propI64 = $context->builder->sext($fn->getParam(1), $i64);
        $context->builder->call(self::registryHelperFunction($context, self::REG_APPEND), $objI64, $propI64);
        self::syncRegistryCountGlobal($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_register', $fn);
    }

    private static function implementGcUnregisterPhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_gc_unregister', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_unregister_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(self::registryHelperFunction($context, self::REG_REMOVE), $objI64);
        self::syncRegistryCountGlobal($context);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_unregister', $fn);
    }

    private static function implementIndexOfPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_index_of');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('gc_index_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $idx = $context->builder->call(self::registryHelperFunction($context, self::REG_INDEX_OF), $objI64);
        $context->builder->returnValue($context->builder->trunc($idx, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructAlreadyInvokedPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_destruct_already_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $entry = $fn->appendBasicBlock('destruct_already_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $inv = $context->builder->call(
            self::registryHelperFunction($context, self::REG_DESTRUCT_ALREADY_BY_OBJ),
            $objI64
        );
        $context->builder->returnValue($context->builder->trunc($inv, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructMarkInvokedPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_destruct_mark_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $entry = $fn->appendBasicBlock('destruct_mark_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(
            self::registryHelperFunction($context, self::REG_MARK_DESTRUCT_BY_OBJ),
            $objI64
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementCollectCyclesPhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_gc_collect_cycles_impl');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        GcCollectCyclesCollectRuntime::ensureCollectHelperCompiled($context);
        $collectFn = self::collectCyclesHelperFunction($context);
        $i32 = $context->getTypeFromString('int32');
        $entry = $fn->appendBasicBlock('collect_impl_php_entry');
        $context->builder->positionAtEnd($entry);
        $collected = $context->builder->call($collectFn);
        $context->builder->returnValue($context->builder->trunc($collected, $i32));
        $context->builder->clearInsertionPosition();
    }

    private static function implementRunShutdownDestructorsPhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = self::functionOrCreate($context, 'phpc_gc_run_shutdown_destructors', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('shutdown_php_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::shutdownHelperFunction($context, self::RUN_SHUTDOWN_DESTRUCTORS)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_run_shutdown_destructors', $fn);
    }

    private static function implementDestructTryInvokePhpBridge(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_destruct_try_invoke', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('destruct_try_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(self::tryInvokeHelperFunction($context, self::TRY_INVOKE), $objI64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_destruct_try_invoke', $fn);
    }

    private static function implementObjectReleaseStoragePhpBridge(Context $context): void
    {
        $fn = $context->lookupFunction('phpc_object_release_storage');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('release_storage_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(self::releaseStorageHelperFunction($context, self::RELEASE_STORAGE), $objI64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureTryInvokeJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::TRY_INVOKE_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::TRY_INVOKE_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcDestructTryInvokeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcDestructTryInvokeJitHelper.php parseAndCompile failed (#18660)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::TRY_INVOKE_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC destruct try-invoke (#18660)');
            }
        }
    }

    private static function ensureReleaseStorageJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::RELEASE_STORAGE_COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::RELEASE_STORAGE_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GcObjectReleaseStorageJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GcObjectReleaseStorageJitHelper.php parseAndCompile failed (#18660)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::RELEASE_STORAGE_COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT GC object release (#18660)');
            }
        }
    }

    private static function tryInvokeHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureTryInvokeJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcDestructTryInvokeJitHelper compile (#18660)');
        }

        return $fn;
    }

    private static function releaseStorageHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureReleaseStorageJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcObjectReleaseStorageJitHelper compile (#18660)');
        }

        return $fn;
    }

    private static function collectCyclesHelperFunction(Context $context): LlvmFunction
    {
        $logical = Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            ? 'PHPCompiler\\ext\\standard\\GcCollectCyclesStandaloneJitHelper::collectCyclesStandalone'
            : 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::collectCyclesEmbed';
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GC collect helper compile (#18630)');
        }

        return $fn;
    }
}
