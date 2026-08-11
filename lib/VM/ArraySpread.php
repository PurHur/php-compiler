<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * Array-literal spread (`[...$x]`) for arrays and Traversables (Zend zend_execute.c / zend_vm_def.h parity).
 *
 * Runtime non-array / non-Traversable unpack throws catchable {@see \Error} (scalars) or
 * {@see \TypeError} (objects) — #27952. Compile-time literal unpack stays an uncatchable
 * compile fatal via {@see \PHPCompiler\Compiler}.
 *
 * PHP 8.4+ appends {@code , <type> given} (#30055; sibling call unpack #30023).
 */
final class ArraySpread
{
    public const NON_TRAVERSABLE_MESSAGE = 'Only arrays and Traversables can be unpacked';

    /**
     * Error/TypeError/Fatal text for non-array / non-Traversable array unpack (zend_vm_def.h ADD_ARRAY_UNPACK).
     *
     * PHP 8.4+ appends {@code , <type> given} via {@see EnumCaseSupport::typeNameForTypeErrorActual()} (#30055).
     */
    public static function nonTraversableMessage(Variable $value): string
    {
        $message = self::NON_TRAVERSABLE_MESSAGE;
        if (!CompilerVersion::supportsUnpackTypeErrorGivenSuffix()) {
            return $message;
        }

        return $message.', '.EnumCaseSupport::typeNameForTypeErrorActual($value).' given';
    }

    /**
     * Same message for compile-time PHP literals / const fetches (IS_CONST unpack → CompileFatal).
     */
    public static function nonTraversableMessageForPhpValue(mixed $value): string
    {
        $message = self::NON_TRAVERSABLE_MESSAGE;
        if (!CompilerVersion::supportsUnpackTypeErrorGivenSuffix()) {
            return $message;
        }
        if (true === $value) {
            $label = 'true';
        } elseif (false === $value) {
            $label = 'false';
        } elseif (null === $value) {
            $label = 'null';
        } elseif (\is_int($value)) {
            $label = 'int';
        } elseif (\is_float($value)) {
            $label = 'float';
        } elseif (\is_string($value)) {
            $label = 'string';
        } elseif (\is_object($value)) {
            $label = $value::class;
        } else {
            $label = get_debug_type($value);
        }

        return $message.', '.$label.' given';
    }

    public static function spreadInto(
        VM $vm,
        Frame $frame,
        HashTable $dest,
        Variable $source,
        int $sourceLine = 0
    ): void {
        unset($sourceLine);
        $source = $source->resolveIndirect();

        if (Variable::TYPE_ARRAY === $source->type) {
            $dest->spreadFrom($source->toArray());

            return;
        }

        if (Variable::TYPE_OBJECT === $source->type && null !== $source->toObject()->generatorState) {
            self::spreadFromGenerator($vm, $source, $dest);

            return;
        }

        if (Variable::TYPE_OBJECT === $source->type) {
            try {
                $iterable = ForeachIterator::resolveTraversableObject($vm, $frame, $source);
            } catch (\TypeError) {
                // Zend: non-Traversable object → catchable TypeError (zend_vm_def.h ADD_ARRAY_UNPACK).
                throw new \TypeError(self::nonTraversableMessage($source));
            }
            if (null !== $iterable->toObject()->generatorState) {
                self::spreadFromGenerator($vm, $iterable, $dest);

                return;
            }
            self::spreadFromIteratorObject($vm, $frame, $iterable, $dest);

            return;
        }

        // Scalars / null — catchable Error (not E_ERROR fatal) (#27952, re-#4812).
        throw new \Error(self::nonTraversableMessage($source));
    }

    private static function spreadFromGenerator(VM $vm, Variable $genVar, HashTable $dest): void
    {
        // Zend zend_generator_rewind opens at the first yield; collect current then next
        // (same shape as Iterator::current/next). resume-first dropped the opening value (#24645).
        $gen = $genVar->toObject()->generatorState;
        $gen->rewind();
        while (!$gen->done && $gen->hasCurrent) {
            self::spreadEntry($dest, $gen->currentKey, $gen->currentValue);
            if (!$vm->resumeGenerator($gen)) {
                break;
            }
        }
    }

    private static function spreadFromIteratorObject(
        VM $vm,
        Frame $frame,
        Variable $iterable,
        HashTable $dest
    ): void {
        $vm->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
        while ($vm->invokeForeachInstanceMethod($frame, $iterable, 'valid')->toBool()) {
            $key = $vm->invokeForeachInstanceMethod($frame, $iterable, 'key');
            $current = $vm->invokeForeachInstanceMethod($frame, $iterable, 'current');
            self::spreadEntry($dest, $key, $current);
            $vm->invokeForeachInstanceMethod($frame, $iterable, 'next');
        }
    }

    private static function spreadEntry(HashTable $dest, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        HashTable::spreadMergeKey($dest, $key, $copy);
    }
}
