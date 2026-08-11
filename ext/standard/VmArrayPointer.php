<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** VM helpers for key/current/next/prev/reset/end (ext/standard/array.c; #4967). */
final class VmArrayPointer
{
    public static function returnKey(Frame $frame, ?Variable $key): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $key) {
            self::writeReturnSlot($frame, null, false);

            return;
        }
        self::writeReturnSlot($frame, $key, false);
    }

    public static function returnValue(Frame $frame, ?Variable $value): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        self::writeReturnSlot($frame, $value, true);
    }

    /**
     * FUNCCALL result slots may alias object property storage (#1885, #11787); stage through INDIRECT
     * so key()/next() returns do not clobber typed instance properties on the operand object.
     */
    private static function writeReturnSlot(Frame $frame, ?Variable $value, bool $valueMode): void
    {
        $dest = $frame->returnVar;
        if (null === $dest) {
            return;
        }
        if ($valueMode && null === $value) {
            $dest->bool(false);

            return;
        }
        if (!$valueMode && null === $value) {
            $dest->null();

            return;
        }
        if (!self::returnVarAliasesPointerObjectProperty($frame, $dest)) {
            $dest->copyFrom($value);

            return;
        }
        // reset/next/end/prev/current/pos return element/property values; copyFrom is safe because
        // object propertyValueAt() already stages a copy (#16556). indirect() on a typed property
        // slot leaves var_export reading bool(true) instead of the value.
        if ($valueMode) {
            $dest->copyFrom($value);

            return;
        }
        // key() returns a fresh string key — copyFrom would clobber typed property storage (#11787).
        $staging = new Variable();
        $staging->copyFrom($value);
        if (Variable::TYPE_INDIRECT === $dest->type) {
            $dest->resolveIndirect()->copyFrom($staging);

            return;
        }
        $dest->indirect($staging);
    }

  private static function returnVarAliasesPointerObjectProperty(Frame $frame, Variable $dest): bool
    {
        if (!isset($frame->calledArgs[0])) {
            return false;
        }
        $operand = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $operand->type) {
            return false;
        }
        $object = $operand->toObject();
        $target = $dest->resolveIndirect();
        if (null === $target->objectPropertyOwner || null === $target->objectPropertyName) {
            return false;
        }

        return $target->objectPropertyOwner === $object;
    }

    /**
     * @throws \TypeError when {@param $value} is not an array or object
     */
    public static function requirePointerTarget(
        Variable $value,
        string $fn,
        bool $mutator,
        ?Context $ctx = null,
        ?Frame $frame = null
    ): VmPointerTarget {
        if (Variable::TYPE_INDIRECT === $value->type) {
            $value = $value->resolveIndirect();
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            if ($mutator) {
                return self::fromArray(self::requireByRefArray($value, $fn));
            }

            return self::fromArray(VmArray::requireArray($value, $fn));
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            self::deprecateObjectPointer($fn, $ctx, $frame);

            return self::fromObject($value->toObject(), $ctx);
        }

        throw new \TypeError(
            \sprintf(
                '%s(): Argument #1 ($array) must be of type array, %s given',
                $fn,
                EnumCaseSupport::typeNameForTypeErrorActual($value)
            )
        );
    }

    public static function fromArray(HashTable $array): VmPointerTarget
    {
        return VmPointerTarget::fromArray($array);
    }

    public static function fromObject(ObjectEntry $object, ?Context $ctx = null): VmPointerTarget
    {
        return VmPointerTarget::fromObject($object, $ctx);
    }

    /**
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireByRefArray(Variable $value, string $fn): HashTable
    {
        if (Variable::TYPE_INDIRECT === $value->type) {
            $value = $value->resolveIndirect();
        }
        if (Variable::TYPE_ARRAY !== $value->type) {
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #1 ($array) must be of type array, %s given',
                    $fn,
                    EnumCaseSupport::typeNameForTypeErrorActual($value)
                )
            );
        }

        return $value->toArray();
    }

    /** PHP 8.1+ E_DEPRECATED when using array-pointer API on objects (ext/standard/array.c; #23574). */
    private static function deprecateObjectPointer(string $fn, ?Context $ctx, ?Frame $frame): void
    {
        if (null === $ctx) {
            return;
        }
        $ctx->errors->triggerErrorWithHandlerFirst(
            \sprintf('%s(): Calling %s() on an object is deprecated', $fn, $fn),
            ErrorReporter::E_DEPRECATED,
            null !== $frame && '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $ctx,
            $frame
        );
    }
}
