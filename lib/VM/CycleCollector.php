<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\GcToggleJitHelper;
use PHPCompiler\Frame;
use PHPCompiler\Web\Superglobals;

/**
 * VM cycle collector entry point — Zend gc_collect_cycles() subset (issue #3113).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c zend_gc_collect_cycles
 */
final class CycleCollector
{
    /** Zend GC root-buffer threshold (zend_gc.c default). */
    public const ROOT_THRESHOLD = 10001;

    /** Zend GC_MAX_BUF_SIZE — zend_gc.c */
    public const MAX_BUFFER_SIZE = 0x40000000;

    /** Zend initial root-buffer capacity (zend_gc.c GC_ROOT_BUFFER_DEFAULT). */
    public const DEFAULT_BUFFER_SIZE = 131072;

    private static int $runs = 0;

    private static int $totalCollected = 0;

    private static bool $running = false;

    private static bool $protected = false;

    /** Nanoseconds from {@see hrtime(true)} when GC status timing starts (#20627). */
    private static ?int $activatedAtNs = null;

    /** Accumulated GC phase times in nanoseconds (zend_gc.c GC_G(*_time), #20627). */
    private static int $collectorTimeNs = 0;

    private static int $destructorTimeNs = 0;

    private static int $freeTimeNs = 0;

    /** Highest {@see ObjectEntry::id} before user script execution (#13437). */
    private static int $baselineObjectMaxId = 0;

    /** @var array<int, true> spl_object_id of arrays registered before user script (#13437). */
    private static array $baselineArrayIds = [];

    public static function captureRequestBaseline(): void
    {
        self::$baselineObjectMaxId = ObjectEntry::maxId();
        self::$baselineArrayIds = [];
        foreach (array_keys(HashTableRegistry::snapshot()) as $arrayId) {
            self::$baselineArrayIds[$arrayId] = true;
        }
    }

    public static function isEnabled(): bool
    {
        return GcToggleJitHelper::isEnabled();
    }

    public static function enable(): void
    {
        GcToggleJitHelper::enable();
    }

    public static function disable(): void
    {
        GcToggleJitHelper::disable();
    }

    public static function isGcProtected(): bool
    {
        return self::$protected;
    }

    /**
     * PHP 8.3+/8.4 gc_status() payload — legacy counters + timing retained (#20627).
     *
     * @return array{
     *     running: bool,
     *     protected: bool,
     *     full: bool,
     *     runs: int,
     *     collected: int,
     *     threshold: int,
     *     buffer_size: int,
     *     roots: int,
     *     application_time: float,
     *     collector_time: float,
     *     destructor_time: float,
     *     free_time: float
     * }
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c ZEND_FUNCTION(gc_status)
     * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c zend_gc_get_status
     */
    public static function status(Context $ctx): array
    {
        $roots = self::countBufferedRoots($ctx);
        $times = self::timingSeconds();

        return [
            'running' => self::$running,
            'protected' => self::$protected,
            'full' => $roots >= self::ROOT_THRESHOLD,
            'runs' => self::$runs,
            'collected' => self::$totalCollected,
            'threshold' => self::ROOT_THRESHOLD,
            'buffer_size' => self::DEFAULT_BUFFER_SIZE,
            'roots' => $roots,
            'application_time' => $times['application_time'],
            'collector_time' => $times['collector_time'],
            'destructor_time' => $times['destructor_time'],
            'free_time' => $times['free_time'],
        ];
    }

    /**
     * @return array{
     *     application_time: float,
     *     collector_time: float,
     *     destructor_time: float,
     *     free_time: float
     * }
     */
    public static function timingSeconds(): array
    {
        self::ensureActivatedAt();
        $now = self::hrtimeNs();
        $activated = self::$activatedAtNs ?? $now;

        return [
            'application_time' => ($now - $activated) / 1_000_000_000.0,
            'collector_time' => self::$collectorTimeNs / 1_000_000_000.0,
            'destructor_time' => self::$destructorTimeNs / 1_000_000_000.0,
            'free_time' => self::$freeTimeNs / 1_000_000_000.0,
        ];
    }

    /**
     * @return array{runs: int, collected: int, threshold: int, roots: int}
     *
     * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_status) pre-8.4
     */
    public static function legacyStatus(Context $ctx): array
    {
        return [
            'runs' => self::$runs,
            'collected' => self::$totalCollected,
            'threshold' => self::ROOT_THRESHOLD,
            'roots' => self::countBufferedRoots($ctx),
        ];
    }

    /** Release VM allocator caches (php_gc.c gc_mem_caches / zend_mm_gc parity, #9160). */
    public static function memCaches(): int
    {
        return MemoryAccounting::releaseMmCaches();
    }

    /** JIT-callable bridge when Superglobals holds the active VM context (#13882). */
    public static function collectActiveContext(): int
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return 0;
        }

        return self::collect($ctx);
    }

    public static function collect(Context $ctx): int
    {
        if (!GcToggleJitHelper::isEnabled()) {
            return 0;
        }
        self::ensureActivatedAt();
        $collectStartNs = self::hrtimeNs();
        self::$running = true;
        self::$protected = true;
        ++self::$runs;
        /** @var array<string, true> $marked */
        $marked = [];
        $visitVar = static function (Variable $var) use (&$marked, &$visitVar): void {
            self::markVariable($var, $marked, $visitVar);
        };

        $ctx->visitGcRoots($visitVar);

        foreach (WeakRefRegistry::weakTargetIds() as $targetId) {
            unset($marked['o'.$targetId]);
        }
        foreach (WeakRefRegistry::weakMapKeyTargetIds() as $targetId) {
            unset($marked['o'.$targetId]);
        }

        $collected = 0;
        /** @var array<int, ObjectEntry> $candidates */
        $candidates = [];
        foreach (ObjectRegistry::snapshot() as $object) {
            if (!isset($marked['o'.$object->id])) {
                $candidates[$object->id] = $object;
            }
        }
        /** @var array<int, HashTable> $arrayCandidates */
        $arrayCandidates = [];
        foreach (HashTableRegistry::snapshot() as $arrayId => $table) {
            if (!isset($marked['a'.$arrayId])) {
                $arrayCandidates[$arrayId] = $table;
            }
        }
        /** @var array<int, true> $peerLinked */
        $peerLinked = [];
        foreach ($candidates as $object) {
            if (self::referencesCandidatePeer($object, $candidates, $arrayCandidates)) {
                $peerLinked[$object->id] = true;
            }
        }
        foreach ($candidates as $object) {
            // Refcount teardown already ran __destruct on a lone orphan — not cyclic GC (#10111).
            if ($object->destructorInvoked && !isset($peerLinked[$object->id])) {
                ObjectRegistry::release($object);

                continue;
            }
            ObjectLifetime::invokeDestructorBeforeGcRelease($object);
            ObjectRegistry::release($object);
            ++$collected;
        }

        foreach ($arrayCandidates as $arrayId => $table) {
            if (WeakRefSupport::isInternalTableForLiveWeakMap($table)) {
                continue;
            }
            if (self::referencesCandidateArrayObjectPeer($table, $candidates)) {
                HashTableRegistry::release($table);
                ++$collected;

                continue;
            }
            if (!self::referencesCandidateArrayPeer($table, $candidates, $arrayCandidates)) {
                // Unreachable compiler literal temp — reclaim only when no live zval refs (#15139, #18612).
                if (0 === $table->getGcRefcount()) {
                    HashTableRegistry::release($table);
                    ++$collected;
                }

                continue;
            }
            if (!self::arrayCandidateInArrayCycle($arrayId, $arrayCandidates)) {
                // Nested literal tree (array→array) without a cycle — skip live arrays (#15139, #18612).
                if (0 === $table->getGcRefcount()) {
                    HashTableRegistry::release($table);
                    ++$collected;
                }

                continue;
            }
            HashTableRegistry::release($table);
            ++$collected;
        }

        self::$totalCollected += $collected;
        self::$running = false;
        self::$protected = false;
        self::$collectorTimeNs += self::hrtimeNs() - $collectStartNs;

        return $collected;
    }

    private static function ensureActivatedAt(): void
    {
        if (null === self::$activatedAtNs) {
            self::$activatedAtNs = self::hrtimeNs();
        }
    }

    private static function hrtimeNs(): int
    {
        $ns = \hrtime(true);

        return \is_int($ns) ? $ns : (int) $ns;
    }

    /**
     * True when an unreachable object still references another GC candidate (#10111).
     *
     * @param array<int, ObjectEntry> $candidates
     */
    /**
     * True when an unreachable object still references another GC candidate (#10111, #13400).
     *
     * @param array<int, ObjectEntry> $objectCandidates
     * @param array<int, HashTable> $arrayCandidates
     */
    private static function referencesCandidatePeer(
        ObjectEntry $object,
        array $objectCandidates,
        array $arrayCandidates
    ): bool {
        foreach ($object->propertiesWithNames() as $prop) {
            if (self::variableReferencesGcCandidates($prop, $objectCandidates, $arrayCandidates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, ObjectEntry> $objectCandidates
     * @param array<int, HashTable> $arrayCandidates
     */
    private static function referencesCandidateArrayPeer(
        HashTable $table,
        array $objectCandidates,
        array $arrayCandidates
    ): bool {
        $selfId = \spl_object_id($table);
        foreach ($table->iterate(false) as $element) {
            if (self::variableReferencesGcCandidates($element, $objectCandidates, $arrayCandidates, $selfId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, ObjectEntry> $objectCandidates
     */
    private static function referencesCandidateArrayObjectPeer(
        HashTable $table,
        array $objectCandidates
    ): bool {
        foreach ($table->iterate(false) as $element) {
            if (self::variableReferencesObjectCandidates($element, $objectCandidates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when an unreachable array candidate participates in an array-only reference cycle.
     *
     * @param array<int, HashTable> $arrayCandidates
     */
    private static function arrayCandidateInArrayCycle(int $startId, array $arrayCandidates): bool
    {
        if (!isset($arrayCandidates[$startId])) {
            return false;
        }
        $visited = [];

        return self::arrayCycleDfs($startId, $arrayCandidates, $visited, []);
    }

    /**
     * @param array<int, true> $visited
     * @param array<int, true> $stack
     * @param array<int, HashTable> $arrayCandidates
     */
    private static function arrayCycleDfs(
        int $nodeId,
        array $arrayCandidates,
        array &$visited,
        array $stack
    ): bool {
        if (isset($stack[$nodeId])) {
            return true;
        }
        if (isset($visited[$nodeId])) {
            return false;
        }
        $visited[$nodeId] = true;
        $stack[$nodeId] = true;
        $table = $arrayCandidates[$nodeId];
        foreach ($table->iterate(false) as $element) {
            foreach (self::arrayCandidatePeerIds($element, $arrayCandidates, $nodeId) as $peerId) {
                if (self::arrayCycleDfs($peerId, $arrayCandidates, $visited, $stack)) {
                    return true;
                }
            }
        }
        unset($stack[$nodeId]);

        return false;
    }

    /**
     * @param array<int, HashTable> $arrayCandidates
     *
     * @return list<int>
     */
    private static function arrayCandidatePeerIds(
        Variable $var,
        array $arrayCandidates,
        int $selfArrayId
    ): array {
        if ($var->isUndefined()) {
            return [];
        }
        if (Variable::TYPE_INDIRECT === $var->type) {
            return self::arrayCandidatePeerIds($var->resolveIndirect(), $arrayCandidates, $selfArrayId);
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            return [];
        }
        try {
            $arrayId = \spl_object_id($var->toArray());
        } catch (\LogicException) {
            return [];
        }
        if (!isset($arrayCandidates[$arrayId])) {
            return [];
        }

        return [$arrayId];
    }

    /**
     * @param array<int, ObjectEntry> $objectCandidates
     */
    private static function variableReferencesObjectCandidates(
        Variable $var,
        array $objectCandidates
    ): bool {
        if ($var->isUndefined()) {
            return false;
        }
        if (Variable::TYPE_INDIRECT === $var->type) {
            return self::variableReferencesObjectCandidates($var->resolveIndirect(), $objectCandidates);
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }
        try {
            $objectId = $var->toObject()->id;
        } catch (\LogicException) {
            return false;
        }

        return isset($objectCandidates[$objectId]);
    }

    /**
     * @param array<int, ObjectEntry> $objectCandidates
     * @param array<int, HashTable> $arrayCandidates
     */
    private static function variableReferencesGcCandidates(
        Variable $var,
        array $objectCandidates,
        array $arrayCandidates,
        ?int $selfArrayId = null
    ): bool {
        if ($var->isUndefined()) {
            return false;
        }
        if (Variable::TYPE_INDIRECT === $var->type) {
            return self::variableReferencesGcCandidates(
                $var->resolveIndirect(),
                $objectCandidates,
                $arrayCandidates,
                $selfArrayId
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            try {
                $objectId = $var->toObject()->id;
            } catch (\LogicException) {
                return false;
            }

            return isset($objectCandidates[$objectId]);
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            try {
                $arrayId = \spl_object_id($var->toArray());
            } catch (\LogicException) {
                return false;
            }

            return ($selfArrayId !== null && $arrayId === $selfArrayId) || isset($arrayCandidates[$arrayId]);
        }

        return false;
    }

    /** Objects not reachable from VM roots — Zend GC root-buffer analogue. */
    private static function countBufferedRoots(Context $ctx): int
    {
        /** @var array<string, true> $marked */
        $marked = [];
        $visitVar = static function (Variable $var) use (&$marked, &$visitVar): void {
            self::markVariable($var, $marked, $visitVar);
        };

        $ctx->visitGcRoots($visitVar);

        foreach (WeakRefRegistry::weakTargetIds() as $targetId) {
            unset($marked['o'.$targetId]);
        }
        foreach (WeakRefRegistry::weakMapKeyTargetIds() as $targetId) {
            unset($marked['o'.$targetId]);
        }

        $roots = 0;
        foreach (ObjectRegistry::snapshot() as $object) {
            if ($object->id <= self::$baselineObjectMaxId) {
                continue;
            }
            if (!isset($marked['o'.$object->id])) {
                ++$roots;
            }
        }
        foreach (HashTableRegistry::snapshot() as $arrayId => $table) {
            if (isset(self::$baselineArrayIds[$arrayId])) {
                continue;
            }
            if (!isset($marked['a'.$arrayId])) {
                ++$roots;
            }
        }

        return $roots;
    }

    /**
     * @param array<string, true> $marked
     * @param callable(Variable): void $visitVar
     */
    private static function markVariable(Variable $var, array &$marked, callable $visitVar): void
    {
        if ($var->isUndefined()) {
            return;
        }

        switch ($var->type) {
            case Variable::TYPE_INDIRECT:
                $visitVar($var->resolveIndirect());

                return;
            case Variable::TYPE_STRING_OFFSET:
                return;
            case Variable::TYPE_ARRAYACCESS_OFFSET:
                return;
            case Variable::TYPE_PROPERTY_HOOK_REF:
                $visitVar($var->propertyHookRefWriteLvalue());

                return;
            case Variable::TYPE_OBJECT:
                $object = $var->toObject();
                if (isset($marked['o'.$object->id])) {
                    return;
                }
                $marked['o'.$object->id] = true;
                foreach ($object->propertiesWithNames() as $name => $prop) {
                    if (WeakRefSupport::shouldSkipGcMark($object, $name)) {
                        continue;
                    }
                    $visitVar($prop);
                }
                if (null !== $object->generatorState) {
                    self::markGeneratorState($object->generatorState, $visitVar);
                }
                if (null !== $object->closureState) {
                    self::markClosureState($object->closureState, $visitVar);
                }

                return;
            case Variable::TYPE_ARRAY:
                $array = $var->toArray();
                $arrayId = \spl_object_id($array);
                if (isset($marked['a'.$arrayId])) {
                    return;
                }
                $marked['a'.$arrayId] = true;
                foreach ($array->iterate(true) as $element) {
                    $visitVar($element);
                }

                return;
        }
    }

    /** @param callable(Variable): void $visitVar */
    private static function markGeneratorState(GeneratorState $state, callable $visitVar): void
    {
        $visitVar($state->currentKey);
        $visitVar($state->currentValue);
        $visitVar($state->yieldFromContainer);
        foreach ($state->calledArgs as $arg) {
            $visitVar($arg);
        }
        if (null !== $state->frame) {
            self::markFrameRoots($state->frame, $visitVar);
        }
    }

    /** @param callable(Variable): void $visitVar */
    private static function markClosureState(ClosureState $state, callable $visitVar): void
    {
        foreach ($state->captures as $capture) {
            $visitVar($capture['var']);
        }
        foreach ($state->staticRootsForCycleCollector() as $static) {
            $visitVar($static);
        }
    }

    /** @param callable(Variable): void $visitVar */
    public static function markFrameRoots(Frame $frame, callable $visitVar, bool $includeParents = true): void
    {
        // Named locals + dynamic locals only — compiler temps in scope[] are not Zend roots (#14827).
        if (null !== $frame->block) {
            foreach ($frame->block->eachNamedScopeSlot() as [, $slot]) {
                if (isset($frame->scope[$slot])) {
                    $visitVar($frame->scope[$slot]);
                }
            }
        }
        foreach ($frame->dynamicLocals as $var) {
            $visitVar($var);
        }
        foreach ($frame->calledArgs as $arg) {
            $visitVar($arg);
        }
        foreach ($frame->callArgs as $arg) {
            $visitVar($arg);
        }
        foreach ($frame->iterators as $iter) {
            $visitVar($iter);
        }
        if (null !== $frame->returnVar) {
            $visitVar($frame->returnVar);
        }
        if (null !== $frame->closureCall) {
            self::markClosureState($frame->closureCall, $visitVar);
        }
        if (null !== $frame->generatorState) {
            self::markGeneratorState($frame->generatorState, $visitVar);
        }
        if ($includeParents && null !== $frame->parent) {
            self::markFrameRoots($frame->parent, $visitVar, true);
        }
    }
}

