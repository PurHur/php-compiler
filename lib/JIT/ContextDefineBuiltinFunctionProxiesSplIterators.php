<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * ArrayIterator / ArrayObject / RecursiveIteratorIterator / LimitIterator /
 * AppendIterator / RegexIterator / CallbackFilterIterator / CachingIterator /
 * NoRewindIterator / InfiniteIterator / EmptyIterator / FilterIterator /
 * ParentIterator / MultipleIterator / RecursiveTreeIterator thin-AOT Call
 * proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the SPL iterator
 * catalog stays a separate TU from SplObjectStorage / SplDllist / WeakMap /
 * DirectoryAndFile wiring (split-TU / size-budget ratchet toward
 * ContextDefineBuiltinFunctionProxies ≤ 200 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesSplIterators;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after SplObjectStorage, before DirectoryAndFile.
 *
 * No new C ABI. php-src analogy: zim_ArrayIterator_* / zim_FilterIterator_* /
 * zim_LimitIterator_* method tables live in ext/spl/spl_array.c and
 * ext/spl/spl_iterators.c beside the executor rather than inside a monolithic
 * MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesSplIterators
{
    private function defineBuiltinFunctionProxiesSplIterators(): void
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
        // RecursiveIteratorIterator — flatten inner HT to LEAVES_ONLY `__spl_ht` (#26775).
        $this->functionProxies['recursiveiteratoriterator::__construct'] = new Call\RecursiveIteratorIteratorConstruct();
        // php-src ZEND_PARSE_PARAMETERS_* — excess argc ArgumentCountError (#30956).
        $this->functionProxies['recursiveiteratoriterator::getdepth'] = new Call\RecursiveIteratorIteratorArgcMethod('getDepth', 0);
        $this->functionProxies['recursiveiteratoriterator::setmaxdepth'] = new Call\RecursiveIteratorIteratorArgcMethod('setMaxDepth', 1);
        $this->functionProxies['recursiveiteratoriterator::getsubiterator'] = new Call\RecursiveIteratorIteratorArgcMethod('getSubIterator', 1);
        // LimitIterator / AppendIterator / RegexIterator / CallbackFilterIterator — `__spl_ht` (#26825, #27259).
        $this->type->object->lookup('LimitIterator');
        $this->type->object->lookup('AppendIterator');
        $this->type->object->lookup('RegexIterator');
        $this->type->object->lookup('CallbackFilterIterator');
        $this->functionProxies['limititerator::__construct'] = new Call\LimitIteratorConstruct();
        $this->functionProxies['limititerator::rewind'] = new Call\LimitIteratorMethod('rewind');
        $this->functionProxies['limititerator::seek'] = new Call\LimitIteratorMethod('seek');
        $this->functionProxies['appenditerator::__construct'] = new Call\AppendIteratorMethod('__construct');
        $this->functionProxies['appenditerator::append'] = new Call\AppendIteratorMethod('append');
        $this->functionProxies['regexiterator::__construct'] = new Call\RegexIteratorConstruct();
        $this->functionProxies['callbackfilteriterator::__construct'] = new Call\CallbackFilterIteratorConstruct();
        $this->type->object->lookup('CachingIterator');
        $this->functionProxies['cachingiterator::__construct'] = new Call\CachingIteratorConstruct();
        $this->functionProxies['cachingiterator::getcache'] = new Call\CachingIteratorGetCache();
        foreach (['getFlags', 'setFlags'] as $citMethod) {
            $this->functionProxies['cachingiterator::'.strtolower($citMethod)] = new Call\CachingIteratorMethod(
                $citMethod
            );
        }
        // NoRewindIterator / InfiniteIterator — HT snapshot + Iterator protocol (#27583 / #27568).
        $this->type->object->lookup('NoRewindIterator');
        $this->type->object->lookup('InfiniteIterator');
        foreach (['__construct', 'rewind', 'valid', 'current', 'key', 'next'] as $nrMethod) {
            $this->functionProxies['norewinditerator::'.strtolower($nrMethod)] = new Call\SplHtPosIteratorMethod(
                $nrMethod,
                'NoRewindIterator',
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::REWIND_NOOP,
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::NEXT_STOP
            );
            $this->functionProxies['infiniteiterator::'.strtolower($nrMethod)] = new Call\SplHtPosIteratorMethod(
                $nrMethod,
                'InfiniteIterator',
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::REWIND_RESET,
                \PHPCompiler\VM\SplHtPosIteratorJitHelper::NEXT_WRAP
            );
        }
        // EmptyIterator — always-invalid; current/key throw (#27582).
        $this->type->object->lookup('EmptyIterator');
        // Eager: get_class select-walk is frozen before method bodies compile (#27582).
        $this->type->object->lookup('BadMethodCallException');
        foreach (['__construct', 'rewind', 'valid', 'current', 'key', 'next'] as $eiMethod) {
            $this->functionProxies['emptyiterator::'.strtolower($eiMethod)] = new Call\EmptyIteratorMethod(
                $eiMethod
            );
        }
        // FilterIterator — HT snapshot + accept() fetch for user subclasses (#27565).
        $this->type->object->lookup('FilterIterator');
        foreach ([
            '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'accept', 'getinneriterator',
        ] as $fiMethod) {
            $this->functionProxies['filteriterator::'.strtolower($fiMethod)] = new Call\FilterIteratorMethod(
                $fiMethod
            );
        }
        // ParentIterator / MultipleIterator / RecursiveTreeIterator — HT snapshot foreach (#27584).
        $this->type->object->lookup('ParentIterator');
        $this->type->object->lookup('MultipleIterator');
        $this->type->object->lookup('RecursiveTreeIterator');
        $this->functionProxies['parentiterator::__construct'] = new Call\ParentIteratorConstruct();
        // php-src ZEND_PARSE_PARAMETERS_NONE; hasChildren ACE cites RecursiveFilterIterator (#30956).
        $this->functionProxies['parentiterator::accept'] = new Call\ParentIteratorArgcMethod(
            'accept',
            'ParentIterator::accept'
        );
        $this->functionProxies['parentiterator::haschildren'] = new Call\ParentIteratorArgcMethod(
            'hasChildren',
            'RecursiveFilterIterator::hasChildren'
        );
        $this->functionProxies['multipleiterator::__construct'] = new Call\MultipleIteratorMethod('__construct');
        $this->functionProxies['multipleiterator::attachiterator'] = new Call\MultipleIteratorMethod('attachIterator');
        $this->functionProxies['recursivetreeiterator::__construct'] = new Call\RecursiveTreeIteratorConstruct();
    }
}
