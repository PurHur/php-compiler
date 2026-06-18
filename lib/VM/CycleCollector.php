<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\GcToggleJitHelper;
use PHPCompiler\Frame;

/**
 * VM cycle collector entry point — Zend gc_collect_cycles() subset (issue #3113).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c zend_gc_collect_cycles
 */
final class CycleCollector
{
    /** Zend GC root-buffer threshold (zend_gc.c default). */
    public const ROOT_THRESHOLD = 10001;

    private static int $runs = 0;

    private static int $totalCollected = 0;

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

    /**
     * @return array{runs: int, collected: int, threshold: int, roots: int}
     *
     * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_status)
     */
    public static function status(Context $ctx): array
    {
        return [
            'runs' => self::$runs,
            'collected' => self::$totalCollected,
            'threshold' => self::ROOT_THRESHOLD,
            'roots' => self::countBufferedRoots($ctx),
        ];
    }

    /** Release VM allocator caches — no Zend MM layer yet (php_gc.c gc_mem_caches subset). */
    public static function memCaches(): int
    {
        $peak = MemoryAccounting::peakBytes();
        $current = MemoryAccounting::currentBytes();
        MemoryAccounting::resetPeakToCurrent();

        return max(0, $peak - $current);
    }

    public static function collect(Context $ctx): int
    {
        if (!GcToggleJitHelper::isEnabled()) {
            return 0;
        }
        ++self::$runs;
        /** @var array<int, true> $marked */
        $marked = [];
        $visitVar = static function (Variable $var) use (&$marked, &$visitVar): void {
            self::markVariable($var, $marked, $visitVar);
        };

        $ctx->visitGcRoots($visitVar);

        foreach (WeakRefRegistry::weakTargetIds() as $targetId) {
            unset($marked[$targetId]);
        }
        foreach (WeakRefRegistry::weakMapKeyTargetIds() as $targetId) {
            unset($marked[$targetId]);
        }

        $collected = 0;
        foreach (ObjectRegistry::snapshot() as $object) {
            if (isset($marked[$object->id])) {
                continue;
            }
            ObjectLifetime::invokeDestructorBeforeGcRelease($object);
            ObjectRegistry::release($object);
            ++$collected;
        }

        self::$totalCollected += $collected;

        return $collected;
    }

    /** Objects not reachable from VM roots — Zend GC root-buffer analogue. */
    private static function countBufferedRoots(Context $ctx): int
    {
        /** @var array<int, true> $marked */
        $marked = [];
        $visitVar = static function (Variable $var) use (&$marked, &$visitVar): void {
            self::markVariable($var, $marked, $visitVar);
        };

        $ctx->visitGcRoots($visitVar);

        foreach (WeakRefRegistry::weakTargetIds() as $targetId) {
            unset($marked[$targetId]);
        }
        foreach (WeakRefRegistry::weakMapKeyTargetIds() as $targetId) {
            unset($marked[$targetId]);
        }

        $roots = 0;
        foreach (ObjectRegistry::snapshot() as $object) {
            if (!isset($marked[$object->id])) {
                ++$roots;
            }
        }

        return $roots;
    }

    /**
     * @param array<int, true> $marked
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
                if (isset($marked[$object->id])) {
                    return;
                }
                $marked[$object->id] = true;
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
                foreach ($var->toArray()->iterate(true) as $element) {
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
    public static function markFrameRoots(Frame $frame, callable $visitVar): void
    {
        foreach ($frame->scope as $slot) {
            $visitVar($slot);
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
        if (null !== $frame->parent) {
            self::markFrameRoots($frame->parent, $visitVar);
        }
    }
}

