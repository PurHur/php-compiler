<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * VM cycle collector entry point — Zend gc_collect_cycles() subset (issue #3113).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c zend_gc_collect_cycles
 */
final class CycleCollector
{
    public static function collect(Context $ctx): int
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

        $collected = 0;
        foreach (ObjectRegistry::snapshot() as $object) {
            if (isset($marked[$object->id])) {
                continue;
            }
            ObjectRegistry::release($object);
            ++$collected;
        }

        return $collected;
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

