<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * ArrayIterator / RecursiveArrayIterator / ArrayObject thin-AOT Call proxies
 * for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxiesSplIterators} so the
 * array-backed SPL catalogs stay a separate TU from LimitIterator /
 * FilterIterator / EmptyIterator peers (split-TU / size-budget ratchet toward
 * SplIterators ≤ 120 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesArrayIteratorAndObject;}
 * on {@see Context}. Invoked from
 * {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after SplContainers, before SplIterators (Limit/Filter family).
 *
 * No new C ABI. php-src analogy: zim_ArrayIterator_* / zim_ArrayObject_* method
 * tables live in ext/spl/spl_array.c beside the executor rather than inside a
 * monolithic SPL iterator MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesArrayIteratorAndObject
{
    private function defineBuiltinFunctionProxiesArrayIteratorAndObject(): void
    {
        // ArrayIterator / RecursiveArrayIterator — `__spl_ht` for thin AOT foreach (#26783, #26775).
        // Seed Countable + ArrayAccess so count()/offset* candidates resolve (#32910).
        $this->type->object->lookup('ArrayIterator');
        $this->type->object->lookup('RecursiveArrayIterator');
        $this->functionProxies['arrayiterator::__construct'] = new Call\ArrayIteratorConstruct('ArrayIterator');
        $this->functionProxies['recursivearrayiterator::__construct'] = new Call\ArrayIteratorConstruct(
            'RecursiveArrayIterator'
        );
        foreach ([
            'count',
            'append',
            // php-src zim_ArrayIterator_getArrayCopy — was missing → silent null (#34002).
            'getArrayCopy',
            'offsetGet',
            'offsetSet',
            'offsetExists',
            'offsetUnset',
            'asort',
            'ksort',
            'natsort',
            'natcasesort',
            // php-src spl_array_object_uasort/uksort — thin AOT was a silent no-op (#33613).
            'uasort',
            'uksort',
            // php-src zim_ArrayIterator_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getFlags',
            'setFlags',
            // php-src zim_ArrayIterator_serialize/unserialize — silent-null (#579 / #35111)
            'serialize',
            'unserialize',
        ] as $aiMethod) {
            $this->functionProxies['arrayiterator::'.strtolower($aiMethod)] = new Call\ArrayIteratorMethod(
                $aiMethod,
                'ArrayIterator'
            );
            $this->functionProxies['recursivearrayiterator::'.strtolower($aiMethod)] = new Call\ArrayIteratorMethod(
                $aiMethod,
                'RecursiveArrayIterator'
            );
        }
        // ArrayObject — same `__spl_ht` construct + count/ArrayAccess/getArrayCopy (#26823).
        $this->type->object->lookup('ArrayObject');
        $this->functionProxies['arrayobject::__construct'] = new Call\ArrayIteratorConstruct('ArrayObject');
        foreach ([
            'count',
            'append',
            'getArrayCopy',
            'exchangeArray',
            'offsetGet',
            'offsetSet',
            'offsetExists',
            'offsetUnset',
            'getIteratorClass',
            'getIterator',
            // php-src spl_array_object_sort — thin AOT was a silent no-op (#33606).
            'asort',
            'ksort',
            'natsort',
            'natcasesort',
            // php-src spl_array_object_uasort/uksort — thin AOT was a silent no-op (#33613).
            'uasort',
            'uksort',
            // php-src zim_ArrayObject_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getFlags',
            'setFlags',
            // php-src zim_ArrayObject_serialize/unserialize — silent-null (#579 / #35111)
            'serialize',
            'unserialize',
        ] as $aoMethod) {
            $this->functionProxies['arrayobject::'.strtolower($aoMethod)] = new Call\ArrayObjectMethod($aoMethod);
        }
    }
}
