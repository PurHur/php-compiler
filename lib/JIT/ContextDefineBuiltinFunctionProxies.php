<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Thin-AOT Call / functionProxies catalog for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltins} so the SPL iterator / SplFile*
 * proxy wiring stays a separate TU from builtin register/implement/initialize
 * (split-TU / size-budget ratchet). Reflection / Exception / Throwable / Error
 * proxies live in {@see ContextDefineBuiltinFunctionProxiesReflectionAndException};
 * DateTime / PDO / XMLReader / XMLWriter / Dom\\TokenList proxies live in
 * {@see ContextDefineBuiltinFunctionProxiesDateAndXml} (#36387 / #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxies;} on {@see Context}.
 * Invoked from {@see ContextDefineBuiltins::defineBuiltins} after implement.
 *
 * No new C ABI. php-src analogy: zim_* method tables and internal function
 * entries are registered beside the executor (ext/spl/spl_*.c, ext/date/,
 * Zend/zend_builtin_functions.c) rather than inside MINIT implement loops.
 */
trait ContextDefineBuiltinFunctionProxies
{
    private function defineBuiltinFunctionProxies(): void
    {
        $this->functionProxies['is_null'] = new Builtin\IsNullFn();
        $this->functionProxies['phpcompiler\\is_null'] = new Builtin\IsNullFn();
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
        // Directory — dir() factory object + read/rewind/close (#30757).
        $this->type->object->lookup('Directory');
        foreach (['__construct', 'read', 'rewind', 'close'] as $dirMethod) {
            $this->functionProxies['directory::'.strtolower($dirMethod)] = new Call\DirectoryMethod(
                $dirMethod
            );
        }
        // DirectoryIterator / FilesystemIterator / RecursiveDirectoryIterator / SplFileInfo —
        // dir snapshot + Iterator (#27289 … #33298, #34624).
        $this->type->object->lookup('SplFileInfo');
        $this->type->object->lookup('DirectoryIterator');
        $this->type->object->lookup('FilesystemIterator');
        $this->type->object->lookup('RecursiveDirectoryIterator');
        foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $diClass) {
            $diLc = strtolower($diClass);
            $diMethods = [
                '__construct', 'rewind', 'valid', 'current', 'key', 'next',
                'isDot', 'getFilename', 'getSize', 'getRealPath',
                'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
                'isFile', 'isDir',
                'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
                'getPathname', 'getPath', 'getExtension', 'getBasename', 'getType', '__toString',
                'getFileInfo', 'getPathInfo', 'openFile',
            ];
            // FilesystemIterator / RDI — getFlags/setFlags (#34984 leftover of #30937).
            if ('DirectoryIterator' !== $diClass) {
                $diMethods[] = 'getFlags';
                $diMethods[] = 'setFlags';
            }
            foreach ($diMethods as $diMethod) {
                $this->functionProxies[$diLc.'::'.strtolower($diMethod)] = new Call\DirectoryIteratorMethod(
                    $diMethod,
                    $diClass
                );
            }
        }
        foreach ([
            '__construct',
            'getFilename', 'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getPathname', 'getPath', 'getExtension', 'getBasename', 'getType', '__toString',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $sfiMethod) {
            $this->functionProxies['splfileinfo::'.strtolower($sfiMethod)] = new Call\DirectoryIteratorMethod(
                $sfiMethod,
                'SplFileInfo'
            );
        }
        // SplFileObject — `__spl_ht` + `__pathname` + `__spl_fd` (#33305/#33308/#33318) + iterator (#33319);
        // getCurrentLine → fgets (#33321); fread/fgetc (#33332); ftell/flock (#33336); fstat (#33359);
        // ftruncate (#33348); fflush (#33354); fpassthru (#33358); fputcsv (#33340); fgetcsv (#33346);
        // fseek (#33347); seek (#33364); setFlags/getFlags (#33368); setMaxLineLen/getMaxLineLen (#33377);
        // setCsvControl/getCsvControl (#33371); fscanf (#33382); hasChildren/getChildren (#33388);
        // inherited SplFileInfo stats (#33313).
        $this->type->object->lookup('SplFileObject');
        foreach ([
            '__construct', 'getFilename', 'getPathname', 'getPath', '__toString',
            'fgets', 'getCurrentLine', 'fread', 'fgetc', 'fwrite', 'fputcsv', 'fgetcsv', 'fscanf',
            'setCsvControl', 'getCsvControl', 'eof', 'hasChildren', 'getChildren',
            'ftell', 'fstat', 'flock', 'ftruncate', 'fflush', 'fpassthru', 'fseek', 'seek',
            'setFlags', 'getFlags', 'setMaxLineLen', 'getMaxLineLen',
            'rewind', 'valid', 'current', 'key', 'next',
        ] as $sfoMethod) {
            $this->functionProxies['splfileobject::'.strtolower($sfoMethod)] = new Call\SplFileObjectMethod(
                $sfoMethod
            );
        }
        foreach ([
            'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getExtension', 'getBasename', 'getType',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $sfoStatMethod) {
            $this->functionProxies['splfileobject::'.strtolower($sfoStatMethod)] = new Call\DirectoryIteratorMethod(
                $sfoStatMethod,
                'SplFileObject'
            );
        }
        // SplTempFileObject — extends SplFileObject; php://temp construct (#33431).
        $this->type->object->lookup('SplTempFileObject');
        foreach ([
            '__construct', 'getFilename', 'getPathname', 'getPath', '__toString',
            'fgets', 'getCurrentLine', 'fread', 'fgetc', 'fwrite', 'fputcsv', 'fgetcsv', 'fscanf',
            'setCsvControl', 'getCsvControl', 'eof', 'hasChildren', 'getChildren',
            'ftell', 'fstat', 'flock', 'ftruncate', 'fflush', 'fpassthru', 'fseek', 'seek',
            'setFlags', 'getFlags', 'setMaxLineLen', 'getMaxLineLen',
            'rewind', 'valid', 'current', 'key', 'next',
        ] as $stfoMethod) {
            $this->functionProxies['spltempfileobject::'.strtolower($stfoMethod)] = new Call\SplFileObjectMethod(
                $stfoMethod,
                true
            );
        }
        foreach ([
            'getSize', 'getRealPath',
            'getMTime', 'getATime', 'getCTime', 'getPerms', 'getOwner', 'getGroup', 'getInode',
            'isFile', 'isDir',
            'isLink', 'getLinkTarget', 'isReadable', 'isWritable', 'isExecutable',
            'getExtension', 'getBasename', 'getType',
            'getFileInfo', 'getPathInfo', 'openFile',
        ] as $stfoStatMethod) {
            $this->functionProxies['spltempfileobject::'.strtolower($stfoStatMethod)] = new Call\DirectoryIteratorMethod(
                $stfoStatMethod,
                'SplFileObject'
            );
        }
        // GlobIterator — glob snapshot + Iterator (#27422); getFlags/setFlags (#34993).
        $this->type->object->lookup('GlobIterator');
        foreach ([
            '__construct', 'rewind', 'valid', 'current', 'key', 'next',
            'getFilename', 'count',
            'getFlags', 'setFlags',
        ] as $giMethod) {
            $this->functionProxies['globiterator::'.strtolower($giMethod)] = new Call\GlobIteratorMethod(
                $giMethod
            );
        }
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

        $this->functionProxies['weakreference::create'] = new Call\WeakReferenceCreate();
        $this->functionProxies['weakreference::get'] = new Call\WeakReferenceGet();
        $this->functionProxies['sensitiveparametervalue::__construct'] = new Call\SensitiveParameterValueConstruct();
        $this->functionProxies['sensitiveparametervalue::getvalue'] = new Call\SensitiveParameterValueGetValue();
        $this->functionProxies['weakmap::offsetset'] = new Call\WeakMapMethod('offsetset');
        $this->functionProxies['weakmap::offsetget'] = new Call\WeakMapMethod('offsetget');
        $this->functionProxies['weakmap::offsetexists'] = new Call\WeakMapMethod('offsetexists');
        $this->functionProxies['weakmap::offsetunset'] = new Call\WeakMapMethod('offsetunset');
        $this->functionProxies['weakmap::count'] = new Call\WeakMapMethod('count');

        // PhpToken OOP API — user-script AOT (#27263 / #6794).
        $this->functionProxies['phptoken::__construct'] = new Call\PhpTokenConstruct();
        $this->functionProxies['phptoken::tokenize'] = new Call\PhpTokenTokenize();
        $this->functionProxies['phptoken::gettokenname'] = new Call\PhpTokenGetTokenName();

        // BcMath\Number thin-AOT Call proxies: registered by ext/bcmath Module::jitInit (#36204).

        // Reflection* / Exception / Throwable / Error — ContextDefineBuiltinFunctionProxiesReflectionAndException (#36387).
        $this->defineBuiltinFunctionProxiesReflectionAndException();

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
        $this->defineBuiltinFunctionProxiesDateAndXml();
    }
}
