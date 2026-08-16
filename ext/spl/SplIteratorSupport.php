<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\VM\Variable;

/** Shared helpers for SPL iterator VM builtins (#6593). */
final class SplIteratorSupport
{
    public static function receiver(Frame $frame, string $classLc, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (strtolower($object->class->name) !== $classLc) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    /** Accept $this when it is $rootClassLc or a registered subclass (php-src internal inheritance). */
    public static function receiverIsA(Frame $frame, string $rootClassLc, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (!self::objectIsA($frame, $object, $rootClassLc)) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }

    private static function objectIsA(Frame $frame, ObjectEntry $object, string $rootClassLc): bool
    {
        $entry = $object->class;
        while (true) {
            if (strtolower(ltrim($entry->name, '\\')) === $rootClassLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc) {
                return false;
            }
            if (null === $frame->vmContext || !isset($frame->vmContext->classes[$parentLc])) {
                return $parentLc === $rootClassLc;
            }
            $entry = $frame->vmContext->classes[$parentLc];
        }
    }

    public static function setReturnBool(Frame $frame, bool $value): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($value);
    }

    public static function setReturnNull(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->null();
    }

    public static function copyReturnFrom(Frame $frame, Variable $source): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($source->resolveIndirect());
    }

    /**
     * Copy a callback for deferred invocation; pin ClosureState when present (#13180).
     *
     * @return array{0: Variable, 1: ?ClosureState}
     */
    public static function pinCallback(Variable $callback): array
    {
        $copy = new Variable();
        $copy->copyFrom($callback->resolveIndirect());
        $closure = VmClosureCall::isClosure($copy) ? VmClosureCall::resolve($copy) : null;

        return [$copy, $closure];
    }

    /**
     * Z_PARAM_FUNC — reject null/invalid callback before storing (php-src spl_iterators.c; #31508, #31574).
     *
     * Unknown string function names use Zend's "function \"…\" not found…" text; other invalid
     * types keep "no array or string given".
     */
    public static function requireCallableArg(
        Variable $var,
        string $function,
        int $argIndex,
        Context $ctx
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($callback) must be a valid callback, no array or string given'
            );
        }
        if (!VmCallable::isCallable($ctx, $resolved)) {
            if (Variable::TYPE_STRING === $resolved->type) {
                throw new \TypeError(
                    $function.'(): Argument #'.$argIndex.' ($callback) must be a valid callback, function "'
                    .$resolved->toString().'" not found or invalid function name'
                );
            }
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($callback) must be a valid callback, no array or string given'
            );
        }

        return $resolved;
    }

    public static function requireArrayArg(Variable $var, string $function, int $argIndex): \PHPCompiler\VM\HashTable
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($array) must be of type array, '
                .self::typeLabel($resolved).' given'
            );
        }

        return $resolved->toArray();
    }

    /**
     * ArrayObject / ArrayIterator / RecursiveArrayIterator construct and
     * ArrayObject::exchangeArray (array|object $array, …).
     *
     * php-src spl_array_set_array (#23886, #31528, #31539): arrays are copied;
     * ArrayObject/ArrayIterator share the live backing table (SPL_ARRAY_USE_OTHER);
     * plain objects materialize public/dynamic properties. Zend TypeError text still
     * says "array" (param name).
     *
     * @return array{0: \PHPCompiler\VM\HashTable, 1: int} table + effective flags
     */
    public static function requireArrayOrObjectConstructArg(
        Variable $var,
        string $function,
        int $argIndex,
        int $userFlags,
        bool $inheritFlagsFromOther
    ): array {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $resolved->type) {
            return [$resolved->toArray()->duplicate(), $userFlags];
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return SplArrayStorage::storageFromConstructObject(
                $resolved->toObject(),
                $userFlags,
                $inheritFlagsFromOther
            );
        }

        throw new \TypeError(
            $function.'(): Argument #'.$argIndex.' ($array) must be of type array, '
            .self::typeLabel($resolved).' given'
        );
    }

    /**
     * Strong roots for ObjectEntry pointers held in SPL sidecars (#6138).
     *
     * Frame teardown uses {@see \PHPCompiler\VM\ObjectLifetime::releaseDirectObject}, which
     * can destroy a temporary Generator even while a Variable pin remains in this bag
     * (refcount vs direct-release skew). Preserve {@see GeneratorState} / ClosureState
     * separately and reattach in {@see ensurePinnedObjectAlive()} before inner calls.
     *
     * @var array<string, Variable>
     */
    private static array $objectPins = [];

    /** @var array<int, GeneratorState> */
    private static array $generatorStatePins = [];

    /** @var array<int, ClosureState> */
    private static array $closureStatePins = [];

    /**
     * Keep an iterator ObjectEntry alive across temporary call-arg release (#6138).
     *
     * @param string $pinKey Stable key for this ownership edge (wrapper id + role)
     */
    public static function pinObject(ObjectEntry $object, string $pinKey = ''): ObjectEntry
    {
        $key = '' !== $pinKey ? $pinKey : 'obj:'.$object->id;
        if (isset(self::$objectPins[$key])) {
            self::$objectPins[$key]->null();
        }
        $slot = new Variable();
        $slot->object($object);
        self::$objectPins[$key] = $slot;
        if (null !== $object->generatorState) {
            self::$generatorStatePins[$object->id] = $object->generatorState;
        }
        if (null !== $object->closureState) {
            self::$closureStatePins[$object->id] = $object->closureState;
        }

        return $object;
    }

    public static function unpinObject(ObjectEntry $object, string $pinKey = ''): void
    {
        $key = '' !== $pinKey ? $pinKey : 'obj:'.$object->id;
        if (isset(self::$objectPins[$key])) {
            self::$objectPins[$key]->null();
            unset(self::$objectPins[$key]);
        } elseif ('' === $pinKey) {
            foreach (array_keys(self::$objectPins) as $storedKey) {
                if (!str_ends_with($storedKey, ':'.$object->id) && $storedKey !== 'obj:'.$object->id) {
                    continue;
                }
                self::$objectPins[$storedKey]->null();
                unset(self::$objectPins[$storedKey]);
            }
        }
        // Drop sidecar state only when no pin keys still reference this object id.
        foreach (self::$objectPins as $storedKey => $_) {
            if (str_ends_with($storedKey, ':'.$object->id) || $storedKey === 'obj:'.$object->id) {
                return;
            }
        }
        unset(self::$generatorStatePins[$object->id], self::$closureStatePins[$object->id]);
    }

    /**
     * Reattach Generator/Closure payload cleared by premature ObjectLifetime GC (#6138, #22874).
     *
     * Frame {@see ObjectLifetime::releaseDirectObject} can drive refcount to 0 while a pin
     * Variable still holds the ObjectEntry. That runs {@see \PHPCompiler\VM::closeGenerator}
     * (marking the pinned {@see GeneratorState} done) and {@see ObjectEntry::destroyForGc}
     * (nulling the live pointer). Restore the pointer and undo a never-started force-close
     * so IteratorIterator / NoRewindIterator / CachingIterator can rewind temp Generators.
     */
    public static function ensurePinnedObjectAlive(ObjectEntry $object): void
    {
        if (null === $object->generatorState && isset(self::$generatorStatePins[$object->id])) {
            $object->generatorState = self::$generatorStatePins[$object->id];
        }
        if (null === $object->closureState && isset(self::$closureStatePins[$object->id])) {
            $object->closureState = self::$closureStatePins[$object->id];
        }
        $gen = $object->generatorState;
        if (null !== $gen && isset(self::$generatorStatePins[$object->id])) {
            // Premature dtor: closeGenerator → markClosedWithoutReturn without ever starting.
            if ($gen->done && !$gen->started && !$gen->hasReturned) {
                $gen->rewind();
                $object->destructorInvoked = false;
            }
        }
        if (!ObjectRegistry::isRegistered($object->id)
            && (isset(self::$generatorStatePins[$object->id]) || isset(self::$closureStatePins[$object->id]))) {
            ObjectRegistry::register($object);
        }
        // Pin Variable still owns the object but skewed releaseDirectObject left refCount at 0.
        if ($object->refCount < 1 && self::pinVariableHolds($object)) {
            ObjectLifetime::addRef($object);
        }
    }

    private static function pinVariableHolds(ObjectEntry $object): bool
    {
        foreach (self::$objectPins as $storedKey => $slot) {
            if (!str_ends_with($storedKey, ':'.$object->id) && $storedKey !== 'obj:'.$object->id) {
                continue;
            }
            if (Variable::TYPE_OBJECT === $slot->type) {
                try {
                    return $slot->toObject()->id === $object->id;
                } catch (\LogicException) {
                    return false;
                }
            }
        }

        return false;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
