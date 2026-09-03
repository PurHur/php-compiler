<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for memory introspection via MemoryJitHelper PHP (#9377, #24058).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer Frexp #22575 / Modf #22519).
 * Replaces RSS/statm LLVM + emalloc globals; SSOT {@see \PHPCompiler\ext\standard\MemoryJitHelper}.
 * php-src: ext/standard/basic_functions.c, Zend/zend_gc.c
 *
 * Thin standalone AOT: NestedJIT MemoryAccounting observes 0 (#27238). Native `__mm__*`
 * (#36388) maintains {@see self::G_EMALLOC_CURRENT}/{@see self::G_EMALLOC_PEAK} from the
 * size header; memory_get_* report max(floor, counter) so the Zend "non-zero" contract
 * holds and short-lived allocations are visible after free.
 */
final class MemoryRuntime
{
    public const NOTE_ALLOC = '__phpc_memory_note_alloc';

    public const GC_MEM_CACHES = '__phpc_gc_mem_caches';

    /** Positive floor when thin AOT NestedJIT counters are unset (#27238 / #36388). */
    public const THIN_AOT_USAGE_FLOOR = 4096;

    public const G_EMALLOC_CURRENT = 'phpc_mm_emalloc_current';

    public const G_EMALLOC_PEAK = 'phpc_mm_emalloc_peak';

    public const EMALLOC_USAGE = '__phpc_mm_emalloc_usage';

    public const EMALLOC_PEAK_USAGE = '__phpc_mm_emalloc_peak_usage';

    public const EMALLOC_REQUEST_RESET = '__phpc_mm_request_reset';

    private const GET_USAGE = '__phpc_memory_get_usage';

    private const GET_PEAK_USAGE = '__phpc_memory_get_peak_usage';

    private const RESET_PEAK_USAGE = '__phpc_memory_reset_peak_usage';

    private const HELPER_PATH = '/ext/standard/MemoryJitHelper.php';

    private const GET_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::getUsage';

    private const GET_PEAK_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::getPeakUsage';

    private const RESET_PEAK_USAGE_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::resetPeakUsage';

    private const NOTE_ALLOC_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::noteAlloc';

    private const GC_MEM_CACHES_HELPER = 'PHPCompiler\\ext\\standard\\MemoryJitHelper::gcMemCaches';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_USAGE_HELPER,
        self::GET_PEAK_USAGE_HELPER,
        self::RESET_PEAK_USAGE_HELPER,
        self::NOTE_ALLOC_HELPER,
        self::GC_MEM_CACHES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function getUsageValue(Context $context, Value $realUsage): Value
    {
        if (self::useThinStandaloneEmallocCounters($context)) {
            $saved = BasicBlockHelper::tryGetInsertBlock($context);
            self::ensureEmallocGlobals($context);
            self::implementEmallocQueryBridges(
                $context,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('void')
            );
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            }

            return $context->builder->call($context->lookupFunction(self::EMALLOC_USAGE));
        }
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::GET_USAGE),
            $realUsage
        );
    }

    public static function getPeakUsageValue(Context $context, Value $realUsage): Value
    {
        if (self::useThinStandaloneEmallocCounters($context)) {
            $saved = BasicBlockHelper::tryGetInsertBlock($context);
            self::ensureEmallocGlobals($context);
            self::implementEmallocQueryBridges(
                $context,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('void')
            );
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            }

            return $context->builder->call($context->lookupFunction(self::EMALLOC_PEAK_USAGE));
        }
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::GET_PEAK_USAGE),
            $realUsage
        );
    }

    private static function useThinStandaloneEmallocCounters(Context $context): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            && $context->isThinStandaloneAotMain();
    }

    public static function resetPeakUsage(Context $context): void
    {
        if (self::useThinStandaloneEmallocCounters($context)) {
            $saved = BasicBlockHelper::tryGetInsertBlock($context);
            self::ensureEmallocGlobals($context);
            self::implementEmallocQueryBridges(
                $context,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('void')
            );
            if (null !== $saved) {
                BasicBlockHelper::restoreInsertBlock($context, $saved);
            }
            // Peak ← current (Zend memory_reset_peak_usage).
            $i64 = $context->getTypeFromString('int64');
            $cur = $context->builder->load(self::emallocGlobalPtr($context, self::G_EMALLOC_CURRENT, $i64));
            $context->builder->store($cur, self::emallocGlobalPtr($context, self::G_EMALLOC_PEAK, $i64));

            return;
        }
        self::ensureLinked($context);
        $context->builder->call($context->lookupFunction(self::RESET_PEAK_USAGE));
    }

    /** Create emalloc counter globals (Native `__mm__*` + thin AOT queries) (#36388). */
    public static function ensureEmallocGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal(self::G_EMALLOC_CURRENT)) {
            $g = $context->module->addGlobal($i64, self::G_EMALLOC_CURRENT);
            $g->setInitializer($i64->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal(self::G_EMALLOC_PEAK)) {
            $g = $context->module->addGlobal($i64, self::G_EMALLOC_PEAK);
            $g->setInitializer($i64->constInt(0, false));
        }
    }

    /**
     * Emit IR: current += delta (saturate at 0); peak = max(peak, current).
     *
     * Called from Native `__mm__malloc` / realloc / free (#36388).
     */
    public static function emitNoteEmallocDelta(Context $context, Value $delta): void
    {
        self::ensureEmallocGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $curPtr = self::emallocGlobalPtr($context, self::G_EMALLOC_CURRENT, $i64);
        $peakPtr = self::emallocGlobalPtr($context, self::G_EMALLOC_PEAK, $i64);
        $cur = $context->builder->load($curPtr);
        $sum = $context->builder->add($cur, $delta);
        $zero = $i64->constInt(0, false);
        $neg = $context->builder->icmp(\PHPLLVM\Builder::INT_SLT, $sum, $zero);
        $parentFn = $context->builder->getInsertBlock()->getParent();
        assert($parentFn instanceof LlvmFunction);
        $satBb = $parentFn->appendBasicBlock('mm_emalloc_sat_zero');
        $posBb = $parentFn->appendBasicBlock('mm_emalloc_pos');
        $joinBb = $parentFn->appendBasicBlock('mm_emalloc_join');
        $context->builder->branchIf($neg, $satBb, $posBb);

        $context->builder->positionAtEnd($satBb);
        $context->builder->store($zero, $curPtr);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($posBb);
        $context->builder->store($sum, $curPtr);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $cur2 = $context->builder->load($curPtr);
        $peak = $context->builder->load($peakPtr);
        $gt = $context->builder->icmp(\PHPLLVM\Builder::INT_SGT, $cur2, $peak);
        $updBb = $parentFn->appendBasicBlock('mm_emalloc_peak_upd');
        $doneBb = $parentFn->appendBasicBlock('mm_emalloc_peak_done');
        $context->builder->branchIf($gt, $updBb, $doneBb);
        $context->builder->positionAtEnd($updBb);
        $context->builder->store($cur2, $peakPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Define `__phpc_mm_emalloc_usage` / peak / request_reset once per module (#36388).
     *
     * @param mixed $i64
     * @param mixed $voidTy
     */
    public static function implementEmallocQueryBridges(Context $context, $i64, $voidTy): void
    {
        self::ensureEmallocGlobals($context);
        self::implementEmallocMaxFloorQuery($context, self::EMALLOC_USAGE, self::G_EMALLOC_CURRENT, $i64);
        self::implementEmallocMaxFloorQuery($context, self::EMALLOC_PEAK_USAGE, self::G_EMALLOC_PEAK, $i64);
        self::implementEmallocRequestReset($context, $i64, $voidTy);
    }

    /**
     * @param mixed $i64
     */
    private static function implementEmallocMaxFloorQuery(
        Context $context,
        string $abiName,
        string $globalName,
        $i64
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_emalloc_query_entry');
        $context->builder->positionAtEnd($entry);
        $cur = $context->builder->load(self::emallocGlobalPtr($context, $globalName, $i64));
        $floor = $i64->constInt(self::THIN_AOT_USAGE_FLOOR, false);
        $gt = $context->builder->icmp(\PHPLLVM\Builder::INT_SGT, $cur, $floor);
        $hi = $fn->appendBasicBlock('mm_emalloc_query_hi');
        $lo = $fn->appendBasicBlock('mm_emalloc_query_lo');
        $context->builder->branchIf($gt, $hi, $lo);
        $context->builder->positionAtEnd($hi);
        $context->builder->returnValue($cur);
        $context->builder->positionAtEnd($lo);
        $context->builder->returnValue($floor);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param mixed $i64
     * @param mixed $voidTy
     */
    private static function implementEmallocRequestReset(Context $context, $i64, $voidTy): void
    {
        $abiName = self::EMALLOC_REQUEST_RESET;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_emalloc_req_reset');
        $context->builder->positionAtEnd($entry);
        $zero = $i64->constInt(0, false);
        $context->builder->store($zero, self::emallocGlobalPtr($context, self::G_EMALLOC_CURRENT, $i64));
        $context->builder->store($zero, self::emallocGlobalPtr($context, self::G_EMALLOC_PEAK, $i64));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param mixed $llvmType
     */
    private static function emallocGlobalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('MemoryRuntime emalloc global missing: '.$name.' (#36388)');
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    public static function noteAlloc(Context $context, Value $delta): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::NOTE_ALLOC),
            $delta
        );
    }

    public static function gcMemCaches(Context $context): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::GC_MEM_CACHES));
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::GET_USAGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Restore caller insert block after bridge emit (#24010 / peer StringFilterBoolean #20988) —
        // clearInsertionPosition left the user-script builder detached
        // ("Current basic block has no parent function") on unset($v) after foreach-by-ref.
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementBoolToI64Bridge($context, self::GET_USAGE, self::GET_USAGE_HELPER);
        self::implementBoolToI64Bridge($context, self::GET_PEAK_USAGE, self::GET_PEAK_USAGE_HELPER);
        self::implementZeroArgVoidBridge($context, self::RESET_PEAK_USAGE, self::RESET_PEAK_USAGE_HELPER);
        self::implementI64VoidBridge($context, self::NOTE_ALLOC, self::NOTE_ALLOC_HELPER);
        self::implementZeroArgI64Bridge($context, self::GC_MEM_CACHES, self::GC_MEM_CACHES_HELPER);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBoolToI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    /** Zero-arg void bridge for memory_reset_peak_usage (#26104). */
    private static function implementZeroArgVoidBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
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

        $entry = $fn->appendBasicBlock('memory_zero_void_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI64VoidBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_note_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, $helperLogical), $fn->getParam(0));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementZeroArgI64Bridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('memory_gc_caches_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, $helperLogical));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24058');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24058'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::GET_USAGE, self::GET_PEAK_USAGE, self::RESET_PEAK_USAGE, self::NOTE_ALLOC, self::GC_MEM_CACHES] as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($abi.' missing after MemoryRuntime bridge (#9377)');
            }
            $context->registerFunction($abi, $fn);
        }
    }
}
