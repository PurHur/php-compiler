<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * SplObjectStorage / SplPriorityQueue / SplDoublyLinkedList / SplQueue /
 * SplStack / SplFixedArray thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the SPL
 * container catalog stays a separate TU from iterator / DirectoryAndFile /
 * WeakMap / PhpToken wiring (split-TU / size-budget ratchet toward
 * ContextDefineBuiltinFunctionProxies ≤ 80 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesSplContainers;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after is_null, before SplIterators.
 *
 * No new C ABI. php-src analogy: zim_SplObjectStorage_* / zim_SplPriorityQueue_* /
 * zim_SplDoublyLinkedList_* / zim_SplFixedArray_* method tables live in
 * ext/spl/spl_observer.c, ext/spl/spl_heap.c, ext/spl/spl_dllist.c, and
 * ext/spl/spl_fixedarray.c beside the executor rather than inside a monolithic
 * MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesSplContainers
{
    private function defineBuiltinFunctionProxiesSplContainers(): void
    {
        $this->functionProxies['splobjectstorage::attach'] = new Call\SplObjectStorageMethod('attach');
        $this->functionProxies['splobjectstorage::contains'] = new Call\SplObjectStorageMethod('contains');
        $this->functionProxies['splobjectstorage::count'] = new Call\SplObjectStorageMethod('count');
        $this->functionProxies['splobjectstorage::offsetexists'] = new Call\SplObjectStorageMethod('offsetexists');
        $this->functionProxies['splobjectstorage::offsetget'] = new Call\SplObjectStorageMethod('offsetget');
        $this->functionProxies['splobjectstorage::offsetset'] = new Call\SplObjectStorageMethod('offsetset');
        // detach / offsetUnset — thin AOT was a silent no-op (#33841; php-src spl_observer.c).
        $this->functionProxies['splobjectstorage::detach'] = new Call\SplObjectStorageMethod('detach');
        $this->functionProxies['splobjectstorage::offsetunset'] = new Call\SplObjectStorageMethod('offsetunset');
        // addAll / removeAll / removeAllExcept — thin AOT was a silent no-op (#33847).
        $this->functionProxies['splobjectstorage::addall'] = new Call\SplObjectStorageMethod('addall');
        $this->functionProxies['splobjectstorage::removeall'] = new Call\SplObjectStorageMethod('removeall');
        $this->functionProxies['splobjectstorage::removeallexcept'] = new Call\SplObjectStorageMethod('removeallexcept');
        // Iterator + getInfo/setInfo for thin AOT (#28707; php-src spl_observer.c).
        $this->functionProxies['splobjectstorage::rewind'] = new Call\SplObjectStorageMethod('rewind');
        $this->functionProxies['splobjectstorage::next'] = new Call\SplObjectStorageMethod('next');
        $this->functionProxies['splobjectstorage::valid'] = new Call\SplObjectStorageMethod('valid');
        $this->functionProxies['splobjectstorage::key'] = new Call\SplObjectStorageMethod('key');
        $this->functionProxies['splobjectstorage::current'] = new Call\SplObjectStorageMethod('current');
        $this->functionProxies['splobjectstorage::getinfo'] = new Call\SplObjectStorageMethod('getinfo');
        $this->functionProxies['splobjectstorage::setinfo'] = new Call\SplObjectStorageMethod('setinfo');
        // getHash — thin AOT returned empty; same wire as spl_object_hash (#33854 / #24292).
        $this->functionProxies['splobjectstorage::gethash'] = new Call\SplObjectStorageMethod('gethash');
        // serialize/unserialize — legacy x:/m: (#35117); without proxy → silent NULL (#579).
        $this->functionProxies['splobjectstorage::serialize'] = new Call\SplObjectStorageMethod('serialize');
        $this->functionProxies['splobjectstorage::unserialize'] = new Call\SplObjectStorageMethod('unserialize');

        // SplHeap family — registered by ext/spl/Module::jitInit (#36204 / #26784).
        // SplPriorityQueue — parallel data/priority HTs + Iterator foreach (#27277, #28708).
        // setExtractFlags/getExtractFlags — thin AOT was a silent null stub (#33861).
        $this->type->object->lookup('SplPriorityQueue');
        foreach ([
            '__construct', 'insert', 'extract', 'top', 'count', 'isEmpty',
            'rewind', 'valid', 'current', 'key', 'next',
            'setExtractFlags', 'getExtractFlags',
        ] as $pqMethod) {
            $this->functionProxies['splpriorityqueue::'.strtolower($pqMethod)] = new Call\SplPriorityQueueMethod($pqMethod);
        }
        // SplDoublyLinkedList / SplQueue / SplStack — `__spl_ht` deque (#26790, #27311, #28704, #32910).
        foreach ([
            'spldoublylinkedlist' => 'SplDoublyLinkedList',
            'splqueue' => 'SplQueue',
            'splstack' => 'SplStack',
        ] as $dllLc => $dllClass) {
            $this->type->object->lookup($dllClass);
            // isEmpty: without proxy, thin AOT silent-nulls (#579) — always falsy (#33973).
            // offset* / setIteratorMode / getIteratorMode: same silent-null without proxy (#33987).
            $dllMethods = [
                '__construct', 'push', 'pop', 'shift', 'unshift', 'top', 'bottom', 'count', 'isempty',
                'offsetGet', 'offsetExists', 'offsetSet', 'offsetUnset',
                'setIteratorMode', 'getIteratorMode',
                // Iterator protocol — without proxy thin AOT silent-nulls (#579 / #34976)
                'rewind', 'valid', 'current', 'key', 'next',
                // Serializable::serialize/unserialize — silent-null (#579 / #35111)
                'serialize', 'unserialize',
            ];
            if ('splqueue' === $dllLc) {
                $dllMethods = array_merge($dllMethods, ['enqueue', 'dequeue']);
            }
            foreach ($dllMethods as $dllMethod) {
                // Lookup keys are lowercase (peer splfixedarray::strtolower); mixed-case
                // offsetGet/setIteratorMode keys missed the table → silent null (#33987).
                $this->functionProxies[$dllLc.'::'.strtolower($dllMethod)] = new Call\SplDllistMethod(
                    $dllMethod,
                    $dllClass
                );
            }
        }
        // SplFixedArray — `__spl_ht` + fromArray / count / setSize / toArray / ArrayAccess / foreach
        // (#26793, #28640, #33784).
        // Seed the class so count()/ArrayAccess candidates see Countable before first use.
        $this->type->object->lookup('SplFixedArray');
        foreach ([
            '__construct', 'fromArray', 'count', 'getSize', 'setSize', 'toArray',
            'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', '__debugInfo',
        ] as $sfaMethod) {
            $this->functionProxies['splfixedarray::'.strtolower($sfaMethod)] = new Call\SplFixedArrayMethod($sfaMethod);
        }
    }
}
