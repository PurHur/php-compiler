<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\ext\standard\JitGcCollectCyclesStandaloneKernel;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM GC registry + cycle collector for JIT/AOT (issues #3160, #5315, #26333).
 *
 * Helper compile: per-file {@see JitVmHelperLink::ensureCompiled} (peer ObGzhandler #26331 /
 * ProcessOpen #26269). Replaces lib/AOT/runtime/phpc_gc.c; semantics mirror Zend
 * gc_collect_cycles subset. php-src: Zend/zend_gc.c
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

    public const G_OBJECTS = 'phpc_gc_objects';

    public const G_PROP_COUNTS = 'phpc_gc_prop_counts';

    private const G_DESTRUCT_INVOKED = 'phpc_destruct_invoked';

    public const G_MARKED = 'phpc_gc_marked';

    public const G_INBOUND = 'phpc_gc_inbound';

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

    /** Re-entrancy guard: NativeOps call ensureLinked while NestedJIT compiles helpers (#21109). */
    private static int $implementDepth = 0;

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
        if (self::$implementDepth > 0) {
            // Mid NestedJIT helper compile: declare call targets only — do not clear insert (#21109).
            self::declareHelperNativeCallTargets($context);
            self::ensureExternals($context);

            return;
        }

        $probe = $context->module->getNamedFunction('phpc_destruct_delref_allowed');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        ++self::$implementDepth;
        try {
            self::$blockSuffix = 0;
            // Declare before helper NestedJIT so NativeOps can emit calls (#21109).
            self::declareHelperNativeCallTargets($context);
            self::ensureExternals($context);
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
        } finally {
            --self::$implementDepth;
        }
    }

    /**
     * Forward-declare LLVM symbols that NestedJIT GC helpers call via NativeOps (#21109).
     */
    private static function declareHelperNativeCallTargets(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        self::declareFunction($context, 'phpc_destruct_try_invoke', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_notify_object_freed', $void, [$i8p]);
        self::declareFunction($context, 'phpc_object_release_storage', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_register', $void, [$i8p, $i32]);
        self::declareFunction($context, 'phpc_gc_unregister', $void, [$i8p]);
        self::declareFunction($context, 'phpc_gc_run_shutdown_destructors', $void, []);
        // __mm__free / __object__invoke_destructor are registered by other runtimes.
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
        if (self::usesPhpRegistry($context)) {
            self::implementGcRegisterPhpBridge($context);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p, $i32);
        $fn = self::functionOrCreate($context, 'phpc_gc_register', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_register_entry');
        $done = $fn->appendBasicBlock('gc_register_done');
        $work = $fn->appendBasicBlock('gc_register_work');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $propCount = $fn->getParam(1);
        $null = $i8p->constNull();
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $max = $i32->constInt(self::MAX_OBJECTS, false);

        $objNull = $context->builder->icmp(Builder::INT_EQ, $obj, $null);
        $atMax = $context->builder->icmp(Builder::INT_SGE, $count, $max);
        $skip = $context->builder->or($objNull, $atMax);
        $context->builder->branchIf($skip, $done, $work);

        $context->builder->positionAtEnd($work);
        $idxFn = $context->lookupFunction('phpc_gc_index_of');
        $idx = $context->builder->call($idxFn, $obj);
        $already = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $insert = $fn->appendBasicBlock('gc_register_insert');
        $context->builder->branchIf($already, $done, $insert);

        $context->builder->positionAtEnd($insert);
        $idxExt = $context->builder->zext($count, $sizeT);
        $context->builder->store($obj, self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $propVal = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $propCount, $i32->constInt(0, false)),
            $propCount,
            $i32->constInt(0, false)
        );
        $context->builder->store($propVal, self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(0, false),
            self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt)
        );
        $context->builder->store(
            $context->builder->add($count, $i32->constInt(1, false)),
            $countPtr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_register', $fn);
    }

    private static function implementGcUnregister(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementGcUnregisterPhpBridge($context);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_gc_unregister', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('gc_unregister_entry');
        $done = $fn->appendBasicBlock('gc_unregister_done');
        $work = $fn->appendBasicBlock('gc_unregister_work');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idxFn = $context->lookupFunction('phpc_gc_index_of');
        $idx = $context->builder->call($idxFn, $obj);
        $found = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($found, $work, $done);

        $context->builder->positionAtEnd($work);
        $countPtr = self::globalPtr($context, self::G_COUNT, $i32);
        $count = $context->builder->load($countPtr);
        $lastIdx = $context->builder->sub($count, $i32->constInt(1, false));
        $needSwap = $context->builder->icmp(Builder::INT_SLT, $idx, $lastIdx);
        $swapBb = $fn->appendBasicBlock('gc_unregister_swap');
        $decBb = $fn->appendBasicBlock('gc_unregister_dec');
        $context->builder->branchIf($needSwap, $swapBb, $decBb);

        $context->builder->positionAtEnd($swapBb);
        $lastExt = $context->builder->zext($lastIdx, $sizeT);
        $idxExt = $context->builder->zext($idx, $sizeT);
        $lastObj = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $lastExt));
        $lastProp = $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $lastExt));
        $i8 = $context->getTypeFromString('int8');
        $lastInv = $context->builder->load(self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $lastExt));
        $context->builder->store($lastObj, self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $context->builder->store($lastProp, self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        $context->builder->store($lastInv, self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt));
        $context->builder->branch($decBb);

        $context->builder->positionAtEnd($decBb);
        $context->builder->store($lastIdx, $countPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_unregister', $fn);
    }

    private static function implementDestructTryInvoke(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementDestructTryInvokePhpBridge($context);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $objPtr = $context->getTypeFromString('__object__*');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = self::functionOrCreate($context, 'phpc_destruct_try_invoke', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('destruct_try_entry');
        $done = $fn->appendBasicBlock('destruct_try_done');
        $work = $fn->appendBasicBlock('destruct_try_work');
        $invoke = $fn->appendBasicBlock('destruct_try_invoke');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $null = $i8p->constNull();
        $objNull = $context->builder->icmp(Builder::INT_EQ, $obj, $null);
        $alreadyFn = $context->lookupFunction('phpc_destruct_already_invoked');
        $already = $context->builder->call($alreadyFn, $obj);
        $alreadySet = $context->builder->icmp(Builder::INT_NE, $already, $i32->constInt(0, false));
        $skip = $context->builder->or($objNull, $alreadySet);
        $context->builder->branchIf($skip, $done, $work);

        $context->builder->positionAtEnd($work);
        $constructed = self::loadObjectConstructed($context, $obj);
        $isConstructed = $context->builder->icmp(Builder::INT_NE, $constructed, $i8->constInt(0, false));
        $context->builder->branchIf($isConstructed, $invoke, $done);

        $context->builder->positionAtEnd($invoke);
        $markFn = $context->lookupFunction('phpc_destruct_mark_invoked');
        $context->builder->call($markFn, $obj);
        $objTyped = $context->builder->pointerCast($obj, $objPtr);
        $context->builder->call($context->lookupFunction('__object__invoke_destructor'), $objTyped);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_destruct_try_invoke', $fn);
    }

    private static function implementRunShutdownDestructors(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementRunShutdownDestructorsPhpBridge($context);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false);
        $fn = self::functionOrCreate($context, 'phpc_gc_run_shutdown_destructors', $ft);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('shutdown_entry');
        $loopHead = $fn->appendBasicBlock('shutdown_loop_head');
        $loopBody = $fn->appendBasicBlock('shutdown_loop_body');
        $loopNext = $fn->appendBasicBlock('shutdown_loop_next');
        $drainHead = $fn->appendBasicBlock('shutdown_drain_head');
        $drainBody = $fn->appendBasicBlock('shutdown_drain_body');
        $done = $fn->appendBasicBlock('shutdown_done');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $iSlot = $context->builder->alloca($i32, 1, 'shutdown_i');
        $count = self::llvmRegistryCount($context);
        $context->builder->store($context->builder->sub($count, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $continueLoop = $context->builder->icmp(Builder::INT_SGE, $i, $i32->constInt(0, false));
        $context->builder->branchIf($continueLoop, $loopBody, $drainHead);

        $context->builder->positionAtEnd($loopBody);
        $i = $context->builder->load($iSlot);
        $invoked = self::llvmRegistryDestructInvoked($context, $i);
        $needsInvoke = $context->builder->icmp(Builder::INT_EQ, $invoked, $i8->constInt(0, false));
        $invokeBb = $fn->appendBasicBlock('shutdown_invoke');
        $context->builder->branchIf($needsInvoke, $invokeBb, $loopNext);

        $context->builder->positionAtEnd($invokeBb);
        $obj = self::llvmRegistryObjectPtr($context, $i);
        $context->builder->call($context->lookupFunction('phpc_destruct_try_invoke'), $obj);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->sub($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($drainHead);
        $context->builder->call(
            $context->lookupFunction('phpc_destruct_set_allow_delref'),
            $i32->constInt(1, false)
        );
        $countNow = self::llvmRegistryCount($context);
        $hasMore = $context->builder->icmp(Builder::INT_SGT, $countNow, $i32->constInt(0, false));
        $context->builder->branchIf($hasMore, $drainBody, $done);

        $context->builder->positionAtEnd($drainBody);
        $lastIdx = $context->builder->sub($countNow, $i32->constInt(1, false));
        $obj = self::llvmRegistryObjectPtr($context, $lastIdx);
        $context->builder->call($context->lookupFunction('phpc_object_release_storage'), $obj);
        $context->builder->branch($drainHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->registerFunction('phpc_gc_run_shutdown_destructors', $fn);
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
        if (!self::usesPhpRegistry($context)) {
            JitGcCollectCyclesStandaloneKernel::ensureCycleScanInternals($context);
        }
        self::implementCollectCyclesImpl($context);
        self::implementObjectReleaseStorage($context);
    }

    private static function implementIndexOf(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementIndexOfPhpBridge($context);

            return;
        }

        $fn = $context->lookupFunction('phpc_gc_index_of');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('gc_index_entry');
        $loopHead = $fn->appendBasicBlock('gc_index_head');
        $loopBody = $fn->appendBasicBlock('gc_index_body');
        $found = $fn->appendBasicBlock('gc_index_found');
        $notFound = $fn->appendBasicBlock('gc_index_not_found');
        $context->builder->positionAtEnd($entry);

        $target = $fn->getParam(0);
        $iSlot = $context->builder->alloca($i32, 1, 'gc_index_i');
        $context->builder->store($i32->constInt(0, false), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $i, $count);
        $context->builder->branchIf($inRange, $loopBody, $notFound);

        $context->builder->positionAtEnd($loopBody);
        $idxExt = $context->builder->zext($i, $sizeT);
        $stored = $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        $match = $context->builder->icmp(Builder::INT_EQ, $stored, $target);
        $next = $fn->appendBasicBlock('gc_index_next');
        $context->builder->branchIf($match, $found, $next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($found);
        $context->builder->returnValue($i);

        $context->builder->positionAtEnd($notFound);
        $context->builder->returnValue($i32->constInt(-1, true));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructAlreadyInvoked(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementDestructAlreadyInvokedPhpBridge($context);

            return;
        }

        $fn = $context->lookupFunction('phpc_destruct_already_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('destruct_already_entry');
        $found = $fn->appendBasicBlock('destruct_already_found');
        $miss = $fn->appendBasicBlock('destruct_already_miss');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $obj);
        $hasIdx = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($hasIdx, $found, $miss);

        $context->builder->positionAtEnd($found);
        $inv = self::llvmRegistryDestructInvoked($context, $idx);
        $context->builder->returnValue($context->builder->zext($inv, $i32));

        $context->builder->positionAtEnd($miss);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function implementDestructMarkInvoked(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementDestructMarkInvokedPhpBridge($context);

            return;
        }

        $fn = $context->lookupFunction('phpc_destruct_mark_invoked');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('destruct_mark_entry');
        $work = $fn->appendBasicBlock('destruct_mark_work');
        $done = $fn->appendBasicBlock('destruct_mark_done');
        $context->builder->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $idx = $context->builder->call($context->lookupFunction('phpc_gc_index_of'), $obj);
        $hasIdx = $context->builder->icmp(Builder::INT_SGE, $idx, $i32->constInt(0, false));
        $context->builder->branchIf($hasIdx, $work, $done);

        $context->builder->positionAtEnd($work);
        self::llvmRegistryMarkDestructInvoked($context, $idx);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObjectReleaseStorage(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementObjectReleaseStoragePhpBridge($context);

            return;
        }

        $fn = $context->lookupFunction('phpc_object_release_storage');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('release_entry');
        $nullRet = $fn->appendBasicBlock('release_null');
        $work = $fn->appendBasicBlock('release_work');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $obj, $i8p->constNull());
        $context->builder->branchIf($isNull, $nullRet, $work);

        $context->builder->positionAtEnd($work);
        $context->builder->call($context->lookupFunction('phpc_gc_notify_object_freed'), $obj);
        $context->builder->call($context->lookupFunction('phpc_gc_unregister'), $obj);
        $context->builder->call($context->lookupFunction('__mm__free'), $obj);
        $context->builder->branch($nullRet);

        $context->builder->positionAtEnd($nullRet);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementCollectCyclesImpl(Context $context): void
    {
        if (self::usesPhpRegistry($context)) {
            self::implementCollectCyclesPhpBridge($context);

            return;
        }

        JitGcCollectCyclesStandaloneKernel::implementCollectCyclesImpl($context);
    }


    private static function loadObjectConstructed(Context $context, Value $objI8): Value
    {
        // Byte-offset load — avoid structGep load typing as [8 x i8] (#21109).
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($objI8, $i8p);
        $constructedPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($raw, $i32->constInt(16, false)),
            $i8->pointerType(0)
        );

        return $context->builder->load($constructedPtr);
    }

    private static function loadObjectRefcount(Context $context, Value $obj): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $objMap = $context->structFieldMap['__object__'] ?? null;
        $refMap = $context->structFieldMap['__ref__'] ?? null;
        if (null !== $objMap && null !== $refMap && isset($objMap['ref'], $refMap['refcount'])) {
            $refField = $context->builder->structGep($obj, $objMap['ref']);
            $refcountPtr = $context->builder->structGep($refField, $refMap['refcount']);

            return $context->builder->load($refcountPtr);
        }
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($obj, $i8p);
        $refcountPtr = $context->builder->pointerCast($raw, $i32->pointerType(0));

        return $context->builder->load($refcountPtr);
    }

    private static function objectHeaderSizeConst(Context $context): Value
    {
        $objTy = $context->getTypeFromString('__object__');
        $one = $context->context->int32Type()->constInt(1, false);

        return $context->builder->pointerCast(
            $context->builder->gep($objTy->pointerType(0)->constNull(), $one),
            $context->getTypeFromString('size_t')
        );
    }

    private static function objectFieldPtr(Context $context, Value $obj, string $field, $fieldType): Value
    {
        $map = $context->structFieldMap['__object__'] ?? null;
        if (null === $map || !isset($map[$field])) {
            throw new \LogicException('__object__ field missing for GC runtime: '.$field);
        }

        return $context->builder->pointerCast(
            $context->builder->structGep($obj, $map[$field]),
            $fieldType->pointerType(0)
        );
    }

    private static function arrayElemPtr(Context $context, string $globalName, $elemType, Value $index): Value
    {
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('GC global missing: '.$globalName);
        }
        $arrayPtr = $context->builder->pointerCast($global, $elemType->pointerType(0)->pointerType(0));
        $elemPtr = $context->builder->inBoundsGEP($arrayPtr, $index);

        return $context->builder->pointerCast($elemPtr, $elemType->pointerType(0));
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
        $standaloneRegistry = !self::usesPhpRegistry($context);

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
        if ($standaloneRegistry && null === $context->module->getNamedGlobal(self::G_OBJECTS)) {
            $ty = $i8p->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_OBJECTS);
            $g->setInitializer($ty->constNull());
        }
        if ($standaloneRegistry && null === $context->module->getNamedGlobal(self::G_PROP_COUNTS)) {
            $ty = $i32->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_PROP_COUNTS);
            $g->setInitializer($ty->constNull());
        }
        if ($standaloneRegistry && null === $context->module->getNamedGlobal(self::G_DESTRUCT_INVOKED)) {
            $ty = $i8->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_DESTRUCT_INVOKED);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MARKED)) {
            $ty = $i8->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_MARKED);
            $g->setInitializer($ty->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_INBOUND)) {
            $ty = $i32->arrayType(self::MAX_OBJECTS);
            $g = $context->module->addGlobal($ty, self::G_INBOUND);
            $g->setInitializer($ty->constNull());
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

            return;
        } catch (\Throwable $e) {
        }
        // The symbol may already exist in the MODULE while being absent from the context registry —
        // LibcExtern adds `memset` and gives it a body via implementMemsetBody() without every
        // caller having registered it. addFunction() on an existing name does not fail; LLVM
        // silently renames the second one to `memset.1`, which carries no body, so the link ends
        // with `undefined reference to memset.1` from phpc_gc_collect_cycles_impl and EVERY AOT
        // binary fails to link (aot-smoke 0/8). Reuse the existing declaration instead, matching
        // the getNamedFunction()-first pattern LibcExtern already uses.
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn) {
            $fn = $context->module->addFunction($name, $ft);
        }
        $context->registerFunction($name, $fn);
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
        return Builtin::LOAD_TYPE_STANDALONE !== $context->loadType;
    }

    private static function ensureDestructAllowDelrefJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::DESTRUCT_HELPER_PATH,
            self::DESTRUCT_COMPILED_HELPERS,
            '#26333'
        );
    }

    private static function destructHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureDestructAllowDelrefJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26333');
    }

    private static function ensureShutdownJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::SHUTDOWN_HELPER_PATH,
            self::SHUTDOWN_COMPILED_HELPERS,
            '#26333'
        );
    }

    private static function shutdownHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureShutdownJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26333');
    }

    private static function ensureRegistryJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::REGISTRY_HELPER_PATH,
            self::REGISTRY_COMPILED_HELPERS,
            '#26333'
        );
    }

    private static function registryHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureRegistryJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26333');
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

    private static function llvmRegistryCount(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if (!self::usesPhpRegistry($context)) {
            return $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
        }

        return $context->builder->trunc(
            $context->builder->call(self::registryHelperFunction($context, self::REG_COUNT)),
            $i32
        );
    }

    private static function llvmRegistryObjectPtr(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        if (!self::usesPhpRegistry($context)) {
            $sizeT = $context->getTypeFromString('size_t');
            $idxExt = $context->builder->zext($index, $sizeT);

            return $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
        }
        $ptrI64 = $context->builder->call(
            self::registryHelperFunction($context, self::REG_OBJECT_PTR),
            $context->builder->sext($index, $i64)
        );

        return $context->builder->intToPtr($ptrI64, $i8p);
    }

    private static function llvmRegistryPropCount(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        if (!self::usesPhpRegistry($context)) {
            $sizeT = $context->getTypeFromString('size_t');
            $idxExt = $context->builder->zext($index, $sizeT);

            return $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
        }

        return $context->builder->trunc(
            $context->builder->call(
                self::registryHelperFunction($context, self::REG_PROP_COUNT),
                $context->builder->sext($index, $i64)
            ),
            $i32
        );
    }

    private static function llvmRegistryDestructInvoked(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        if (!self::usesPhpRegistry($context)) {
            $sizeT = $context->getTypeFromString('size_t');
            $idxExt = $context->builder->zext($index, $sizeT);

            return $context->builder->load(self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt));
        }
        $flag = $context->builder->call(
            self::registryHelperFunction($context, self::REG_DESTRUCT_INVOKED),
            $context->builder->sext($index, $i64)
        );

        return $context->builder->zext($flag, $i8);
    }

    private static function llvmRegistryMarkDestructInvoked(Context $context, Value $index): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        if (!self::usesPhpRegistry($context)) {
            $sizeT = $context->getTypeFromString('size_t');
            $idxExt = $context->builder->zext($index, $sizeT);
            $context->builder->store(
                $i8->constInt(1, false),
                self::arrayElemPtr($context, self::G_DESTRUCT_INVOKED, $i8, $idxExt)
            );

            return;
        }
        $context->builder->call(
            self::registryHelperFunction($context, self::REG_MARK_DESTRUCT),
            $context->builder->sext($index, $i64)
        );
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
        $collectEmbed = self::collectEmbedHelperFunction($context);
        $i32 = $context->getTypeFromString('int32');
        $entry = $fn->appendBasicBlock('collect_impl_php_entry');
        $context->builder->positionAtEnd($entry);
        $collected = $context->builder->call($collectEmbed);
        // NestedJIT PHP int is i64; ABI is i32 (#21109).
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
        $entry = $fn->appendBasicBlock('release_storage_php_entry');
        $context->builder->positionAtEnd($entry);
        $objI64 = $context->builder->ptrToInt($fn->getParam(0), $i64);
        $context->builder->call(self::releaseStorageHelperFunction($context, self::RELEASE_STORAGE), $objI64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureTryInvokeJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::TRY_INVOKE_HELPER_PATH,
            self::TRY_INVOKE_COMPILED_HELPERS,
            '#26333'
        );
    }

    private static function ensureReleaseStorageJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::RELEASE_STORAGE_HELPER_PATH,
            self::RELEASE_STORAGE_COMPILED_HELPERS,
            '#26333'
        );
    }

    private static function tryInvokeHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureTryInvokeJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26333');
    }

    private static function releaseStorageHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureReleaseStorageJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26333');
    }

    private static function collectEmbedHelperFunction(Context $context): LlvmFunction
    {
        $logical = 'PHPCompiler\\ext\\standard\\GcCollectCyclesJitHelper::collectCyclesEmbed';
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GcCollectCyclesJitHelper compile (#13882)');
        }

        return $fn;
    }

    /** @internal Standalone AOT LLVM helpers for {@see JitGcCollectCyclesStandaloneKernel} (#17015, #19596). */
    public static function standaloneArrayElemPtr(Context $context, string $globalName, $elemType, Value $index): Value
    {
        return self::arrayElemPtr($context, $globalName, $elemType, $index);
    }

    public static function standaloneGlobalPtr(Context $context, string $name, $llvmType): Value
    {
        return self::globalPtr($context, $name, $llvmType);
    }

    public static function standaloneRegistryCount(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->load(self::globalPtr($context, self::G_COUNT, $i32));
    }

    public static function standaloneRegistryObjectPtr(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $idxExt = $context->builder->zext($index, $sizeT);

        return $context->builder->load(self::arrayElemPtr($context, self::G_OBJECTS, $i8p, $idxExt));
    }

    public static function standaloneRegistryPropCount(Context $context, Value $index): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $idxExt = $context->builder->zext($index, $sizeT);

        return $context->builder->load(self::arrayElemPtr($context, self::G_PROP_COUNTS, $i32, $idxExt));
    }

    public static function standaloneObjectHeaderSizeConst(Context $context): Value
    {
        return self::objectHeaderSizeConst($context);
    }

    public static function standaloneLoadObjectRefcount(Context $context, Value $obj): Value
    {
        return self::loadObjectRefcount($context, $obj);
    }
}
