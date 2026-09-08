<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPLLVM;

/**
 * Builtin register/implement + functionProxies wiring for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so the ~900-line defineBuiltins catalog stays a
 * separate TU from the Context construction / compile hub (split-TU / size-budget
 * ratchet toward Context ≤ 4k lines). One-file-edit / edit-scaffold skip paths
 * stay with this trait (#36199).
 *
 * Used via {@code use ContextDefineBuiltins;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend registers internal functions and class
 * methods into the executor globals at MINIT (Zend/zend_builtin_functions.c,
 * ext/standard/php_standard.h and peers) — a catalog separate from the compiler proper.
 */
trait ContextDefineBuiltins
{
    private function defineBuiltins(int $loadType): void {
        // Stale sg_* from a prior JITContext in the same PHP process breaks SessionDestroy::implement (#4415).
        SuperglobalInit::$globals = [];
        LibcExtern::register($this);
        if (!CompileCache::shouldSkipBuiltinImplement()) {
            LibcExtern::implementMcjitMemBodies($this);
        }
        foreach ($this->builtins as $builtin) {
            // Restored bitcode already has __mm__malloc etc. addFunction would mint
            // empty __mm__malloc.1 and lookupFunction would bind the stub (#36387).
            // Thin helper-cache AOT may restore bitcode without the mm family — still
            // register when the decls are absent (str-builder concat flatten, #36386).
            if (CompileCache::shouldSkipBuiltinImplement()
                && $builtin instanceof Builtin\MemoryManager) {
                $mm = $this->module->getNamedFunction('__mm__malloc');
                if ($mm instanceof PHPLLVM\Value\Function_) {
                    $this->registerFunction('__mm__malloc', $mm);
                    $realloc = $this->module->getNamedFunction('__mm__realloc');
                    if ($realloc instanceof PHPLLVM\Value\Function_) {
                        $this->registerFunction('__mm__realloc', $realloc);
                    }
                    $free = $this->module->getNamedFunction('__mm__free');
                    if ($free instanceof PHPLLVM\Value\Function_) {
                        $this->registerFunction('__mm__free', $free);
                    }
                } else {
                    $builtin->register();
                }
                continue;
            }
            $builtin->register();
        }
        if ($loadType === Builtin::LOAD_TYPE_IMPORT) {
            return;
        }
        // Edit-scaffold: helpers already defined in restored bitcode — skip implement IR (#36387).
        if (!CompileCache::shouldSkipBuiltinImplement()) {
        foreach ($this->builtins as $builtin) {
            // this is a separate loop, since initialize may
            // depend on functions defined during implement()
            // so this way, cross-builtin dependencies are honored
            $builtin->implement();
        }
        McjitEmbedRuntime::finalizeModule($this);
        $this->ensureInitShutdownBlocks();

        foreach ($this->builtins as $builtin) {
            $builtin->initialize();
        }

        SuperglobalInit::initialize($this);
        CliArgvGlobalInit::initialize($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            || Builtin::LOAD_TYPE_EMBED === $this->loadType) {
            SuperglobalInit::declareRefresh($this);
            SuperglobalInit::implementRefresh($this);
        }

        Builtin\ReflectionNative::registerDeclarations($this);
        Builtin\AttributeRegistry::registerDeclarations($this);
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType) {
            if ($this->isUserScriptAot()) {
                $this->ensureMinimalUserStandaloneBodies();
            } elseif ($this->shouldUseBootstrapAotStandaloneBodies()) {
                $this->ensureBootstrapAotStandaloneBodies();
            } else {
                $this->ensureFullStandaloneBodies();
            }
        }
        } else {
            // Thin bitcode restore without mm bodies: implement MemoryManager only when
            // we had to mint empty __mm__* decls above (#36386 / str-builder helper=1).
            foreach ($this->builtins as $builtin) {
                if (!$builtin instanceof Builtin\MemoryManager) {
                    continue;
                }
                $mm = $this->module->getNamedFunction('__mm__malloc');
                if ($mm instanceof PHPLLVM\Value\Function_ && 0 === $mm->countBasicBlocks()) {
                    $builtin->implement();
                }
                break;
            }
        }

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

        $this->functionProxies['reflectionclass::__construct'] = new Call\ReflectionClassConstruct();
        $this->functionProxies['reflectionobject::__construct'] = new Call\ReflectionObjectConstruct();
        $this->functionProxies['reflectionclass::getname'] = new Call\ReflectionClassGetName();
        // Thin AOT: ReflectionObject::$name / getName empty without construct + TYPE_VALUE (#34001).
        $this->functionProxies['reflectionobject::getname'] = new Call\ReflectionObjectGetName();
        $this->functionProxies['reflectionclass::getshortname'] = new Call\ReflectionClassGetShortName();
        $this->functionProxies['reflectionclass::getnamespacename'] = new Call\ReflectionClassGetNamespaceName();
        $this->functionProxies['reflectionclass::innamespace'] = new Call\ReflectionClassInNamespace();
        $this->functionProxies['reflectionclass::getattributes'] = new Call\ReflectionClassGetAttributes();

        $this->functionProxies['reflectionclass::getmethod'] = new Call\ReflectionClassGetMethod();
        // Thin AOT: unbound getConstructor → unseeded ReflectionMethod → SIGSEGV (#34073).
        $this->functionProxies['reflectionclass::getconstructor'] = new Call\ReflectionClassGetConstructor();
        $this->functionProxies['reflectionclass::getproperty'] = new Call\ReflectionClassGetProperty();
        $this->functionProxies['reflectionclass::getreflectionconstant'] = new Call\ReflectionClassGetReflectionConstant();
        // Thin AOT: unbound hasMethod/hasProperty/hasConstant → NULL (#34072); VM #6301.
        $this->functionProxies['reflectionclass::hasmethod'] = new Call\ReflectionClassHasMember('hasMethod');
        $this->functionProxies['reflectionclass::hasproperty'] = new Call\ReflectionClassHasMember('hasProperty');
        $this->functionProxies['reflectionclass::hasconstant'] = new Call\ReflectionClassHasMember('hasConstant');
        // Thin AOT: unbound getConstant → NULL (#34093); VM ReflectionClassGetConstant (#6950).
        $this->functionProxies['reflectionclass::getconstant'] = new Call\ReflectionClassGetConstant();
        // Thin AOT: unbound getConstants → NULL (#34109); VM ReflectionClassGetConstants (#6950).
        $this->functionProxies['reflectionclass::getconstants'] = new Call\ReflectionClassGetConstants();
        // Thin AOT: unbound getReflectionConstants → NULL (#34119); VM #6662.
        $this->functionProxies['reflectionclass::getreflectionconstants'] = new Call\ReflectionClassGetReflectionConstants();
        // Thin AOT: unbound getFileName → NULL/SIGSEGV (#34096); VM ReflectionClassGetFileName (#7358).
        $this->functionProxies['reflectionclass::getfilename'] = new Call\ReflectionClassGetFileName();
        // Thin AOT: unbound getStartLine/getEndLine/getDocComment → NULL (#34106);
        // once-per-module helpers — inlined emit SIGSEGV under typed show() thrice (#34186).
        $this->functionProxies['reflectionclass::getstartline'] = new Call\ReflectionClassSourceLocationQuery('getStartLine');
        $this->functionProxies['reflectionclass::getendline'] = new Call\ReflectionClassSourceLocationQuery('getEndLine');
        $this->functionProxies['reflectionclass::getdoccomment'] = new Call\ReflectionClassSourceLocationQuery('getDocComment');
        // Thin AOT: unbound getInterfaceNames/getTraitNames → NULL (#34110); Object_ interface/trait tables.
        $this->functionProxies['reflectionclass::getinterfacenames'] = new Call\ReflectionClassNameListQuery('interfacenames');
        $this->functionProxies['reflectionclass::gettraitnames'] = new Call\ReflectionClassNameListQuery('traitnames');
        // Thin AOT: unbound getInterfaces/getTraits → NULL (#34121); VM #22170 / #22108.
        $this->functionProxies['reflectionclass::getinterfaces'] = new Call\ReflectionClassClassMapQuery('interfaces');
        $this->functionProxies['reflectionclass::gettraits'] = new Call\ReflectionClassClassMapQuery('traits');
        // Thin AOT: unbound getTraitAliases → NULL (#34129); VM #6661.
        $this->functionProxies['reflectionclass::gettraitaliases'] = new Call\ReflectionClassGetTraitAliases();
        // Thin AOT: unbound __toString → convert-to-string fatal (#34135); VM #22379.
        $this->functionProxies['reflectionclass::__tostring'] = new Call\ReflectionClassToString();
        // Thin AOT: unbound implementsInterface/isSubclassOf → NULL → false (#34080); VM #6302.
        $this->functionProxies['reflectionclass::implementsinterface'] = new Call\ReflectionClassRelationQuery('implementsInterface');
        $this->functionProxies['reflectionclass::issubclassof'] = new Call\ReflectionClassRelationQuery('isSubclassOf');
        // Thin AOT: isFinal used broken strcasecmp → always true (#34043); memcmp+fold table.
        $this->functionProxies['reflectionclass::isfinal'] = new Call\ReflectionClassIsFinal();
        // Thin AOT: unbound isInstantiable → NULL (#34027); VM has ReflectionClassIsInstantiable.
        $this->functionProxies['reflectionclass::isinstantiable'] = new Call\ReflectionClassIsInstantiable();
        // Thin AOT: unbound isInstance → NULL (#34098); VM #6302 / peer instanceof tables.
        $this->functionProxies['reflectionclass::isinstance'] = new Call\ReflectionClassIsInstance();
        // Thin AOT: unbound isCloneable → NULL (#34040); VM has ReflectionClassIsCloneable (#22109).
        $this->functionProxies['reflectionclass::iscloneable'] = new Call\ReflectionClassIsCloneable();
        // Thin AOT: unbound isAnonymous → NULL (#34057); VM has ReflectionClassIsAnonymous (#5105).
        $this->functionProxies['reflectionclass::isanonymous'] = new Call\ReflectionClassIsAnonymous();
        // Thin AOT: unbound kind queries → NULL (#34032); NestedJIT emitKindQuery fails verify.
        $this->functionProxies['reflectionclass::isinterface'] = new Call\ReflectionClassKindQuery('isInterface');
        $this->functionProxies['reflectionclass::isabstract'] = new Call\ReflectionClassKindQuery('isAbstract');
        $this->functionProxies['reflectionclass::istrait'] = new Call\ReflectionClassKindQuery('isTrait');
        $this->functionProxies['reflectionclass::isenum'] = new Call\ReflectionClassKindQuery('isEnum');
        // Thin AOT: unbound isInternal/isUserDefined/isReadOnly → NULL (#34067); peer #34032 tables.
        $this->functionProxies['reflectionclass::isinternal'] = new Call\ReflectionClassKindQuery('isInternal');
        $this->functionProxies['reflectionclass::isuserdefined'] = new Call\ReflectionClassKindQuery('isUserDefined');
        $this->functionProxies['reflectionclass::isreadonly'] = new Call\ReflectionClassKindQuery('isReadOnly');
        // Thin AOT: isIterable looked up unlinked NestedJIT ABI → compile abort (#34062).
        $this->functionProxies['reflectionclass::isiterateable'] = new Call\ReflectionClassIsIterateable();
        $this->functionProxies['reflectionclass::isiterable'] = new Call\ReflectionClassIsIterateable();
        // Thin AOT: getParentClass without proxy → SIGSEGV on result use (#34069).
        $this->functionProxies['reflectionclass::getparentclass'] = new Call\ReflectionClassGetParentClass();
        // Thin AOT: unbound getModifiers → NULL (#34077); VM has ReflectionClassGetModifiers (#18335).
        $this->functionProxies['reflectionclass::getmodifiers'] = new Call\ReflectionClassGetModifiers();
        // Thin AOT: unbound newInstanceWithoutConstructor → abort rc=134 (#34078); VM #5443.
        $this->functionProxies['reflectionclass::newinstancewithoutconstructor'] = new Call\ReflectionClassNewInstanceWithoutConstructor();
        // Thin AOT: unbound newInstance → abort rc=134 (#34083); VM #22086.
        $this->functionProxies['reflectionclass::newinstance'] = new Call\ReflectionClassNewInstance();
        // Thin AOT: unbound newInstanceArgs → NULL (#34090); VM #22086.
        $this->functionProxies['reflectionclass::newinstanceargs'] = new Call\ReflectionClassNewInstanceArgs();
        // Thin AOT: unbound getDefaultProperties → NULL (#34091); VM #11441 / peer get_class_vars #27229.
        $this->functionProxies['reflectionclass::getdefaultproperties'] = new Call\ReflectionClassGetDefaultProperties();
        // Thin AOT: unbound getMethods → NULL (#34107); VM #3815.
        $this->functionProxies['reflectionclass::getmethods'] = new Call\ReflectionClassGetMethods();
        // Thin AOT: unbound getProperties → NULL (#34113); VM #3815.
        $this->functionProxies['reflectionclass::getproperties'] = new Call\ReflectionClassGetProperties();
        // Thin AOT: unbound getStaticProperties → NULL (#34118); VM #6948.
        $this->functionProxies['reflectionclass::getstaticproperties'] = new Call\ReflectionClassGetStaticProperties();
        // Thin AOT: unbound getStaticPropertyValue → NULL (#34125); VM #6948 / peer getConstant #34093.
        $this->functionProxies['reflectionclass::getstaticpropertyvalue'] = new Call\ReflectionClassGetStaticPropertyValue();
        // Thin AOT: unbound setStaticPropertyValue → silent no-op (#34130); VM #6948.
        $this->functionProxies['reflectionclass::setstaticpropertyvalue'] = new Call\ReflectionClassSetStaticPropertyValue();
        // Thin AOT: unbound getExtensionName → NULL (#34139); VM #7358 / peer getFileName #34096.
        $this->functionProxies['reflectionclass::getextensionname'] = new Call\ReflectionClassGetExtensionName();
        // Thin AOT: unbound getExtension → NULL (#34145); VM #11462 / peer #34139.
        $this->functionProxies['reflectionclass::getextension'] = new Call\ReflectionClassGetExtension();
        if (CompilerVersion::supportsLazyObjectFactories()) {
            $this->functionProxies['reflectionclass::newlazyproxy'] = new Call\ReflectionClassNewLazyProxy();
            $this->functionProxies['reflectionclass::newlazyghost'] = new Call\ReflectionClassNewLazyGhost();
            // ReflectionClass::createLazyGhost/Proxy are phantoms vs php-src (#28516).
        }
        $this->functionProxies['reflectionproperty::__construct'] = new Call\ReflectionPropertyConstruct();
        $this->functionProxies['reflectionproperty::getname'] = new Call\ReflectionPropertyGetName();
        $this->functionProxies['reflectionparameter::__construct'] = new Call\ReflectionParameterConstruct();
        $this->functionProxies['reflectionparameter::getname'] = new Call\ReflectionParameterGetName();
        $this->functionProxies['reflectionparameter::gettype'] = new Call\ReflectionParameterGetType();
        $this->functionProxies['reflectionparameter::hastype'] = new Call\ReflectionParameterHasType();
        $this->functionProxies['reflectionparameter::allowsnull'] = new Call\ReflectionParameterAllowsNull();
        $this->functionProxies['reflectionparameter::isdefaultvalueavailable'] = new Call\ReflectionParameterIsDefaultValueAvailable();
        $this->functionProxies['reflectionparameter::getdefaultvalue'] = new Call\ReflectionParameterGetDefaultValue();
        $this->functionProxies['reflectionproperty::getattributes'] = new Call\ReflectionPropertyGetAttributes();
        // Thin AOT: isFinal used broken strcasecmp → true for every prop when table non-empty (#34047).
        $this->functionProxies['reflectionproperty::isfinal'] = new Call\ReflectionPropertyIsFinal();
        $this->functionProxies['reflectionproperty::isvirtual'] = new Call\ReflectionPropertyIsVirtual();
        $this->functionProxies['reflectionproperty::getrawvalue'] = new Call\ReflectionPropertyGetRawValue();
        $this->functionProxies['reflectionproperty::setrawvalue'] = new Call\ReflectionPropertySetRawValue();
        // Thin AOT: avoid undefined setaccessible / null invoke (#30910).
        $this->functionProxies['reflectionproperty::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionProperty');
        $this->functionProxies['reflectionproperty::getvalue'] = new Call\ReflectionPropertyGetValue();
        $this->functionProxies['reflectionproperty::setvalue'] = new Call\ReflectionPropertySetValue();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionproperty::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionProperty',
            \PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME,
            'ReflectionProperty::getDeclaringClass'
        );
        // Thin AOT: unset $class/$name → SIGSEGV on property read / getAttributes (#33990).
        $this->functionProxies['reflectionmethod::__construct'] = new Call\ReflectionMethodConstruct();
        // getName was still unbound after #33994 — silent empty string (#33990 done-when).
        $this->functionProxies['reflectionmethod::getname'] = new Call\ReflectionMethodGetName();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionmethod::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionMethod',
            \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_METHOD_CLASS,
            'ReflectionMethod::getDeclaringClass'
        );
        $this->functionProxies['reflectionmethod::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionMethod');
        $this->functionProxies['reflectionmethod::invoke'] = new Call\ReflectionMethodInvoke();
        // Thin AOT: unbound isPublic/isStatic/param counts → NULL (#34216).
        $this->functionProxies['reflectionmethod::ispublic'] = new Call\ReflectionMethodIsPublic();
        $this->functionProxies['reflectionmethod::isstatic'] = new Call\ReflectionMethodIsStatic();
        $this->functionProxies['reflectionmethod::getnumberofparameters'] = new Call\ReflectionMethodGetNumberOfParameters();
        $this->functionProxies['reflectionmethod::getnumberofrequiredparameters'] = new Call\ReflectionMethodGetNumberOfRequiredParameters();
        // Thin AOT: unbound hasReturnType blocks Nyholm StreamTrait top-level guard (#36382).
        $this->functionProxies['reflectionmethod::hasreturntype'] = new Call\ReflectionMethodHasReturnType();
        $this->functionProxies['reflectionclassconstant::__construct'] = new Call\ReflectionClassConstantConstruct();
        $this->functionProxies['reflectionclassconstant::getname'] = new Call\ReflectionClassConstantGetName();
        if (CompilerVersion::supportsReflectionPropertyGetMangledName()) {
            $this->functionProxies['reflectionproperty::getmangledname'] = new Call\ReflectionPropertyGetMangledName();
        }

        $this->functionProxies['reflectionconstant::__construct'] = new Call\ReflectionConstantConstruct();
        $this->functionProxies['reflectionconstant::getname'] = new Call\ReflectionConstantGetName();
        $this->functionProxies['reflectionconstant::getvalue'] = new Call\ReflectionConstantGetValue();
        // PHP 8.5+ only — withhold on ≤8.4 profiles (#28157).
        if (CompilerVersion::advertisesReflectionConstantGetAttributes()) {
            $this->functionProxies['reflectionconstant::getattributes'] = new Call\ReflectionConstantGetAttributes();
        }
        // ReflectionClassConstant::$class+$name layout — not ReflectionConstant::$name+$constant (#25963).
        $this->functionProxies['reflectionclassconstant::getattributes'] = new Call\ReflectionClassConstantGetAttributes();
        $this->functionProxies['reflectionmethod::getattributes'] = new Call\ReflectionMethodGetAttributes();
        $this->functionProxies['reflectionfunction::__construct'] = new Call\ReflectionFunctionConstruct();
        $this->functionProxies['reflectionfunction::getname'] = new Call\ReflectionFunctionGetName();
        // Thin AOT: unset extension name → empty getName() (#34003).
        $this->functionProxies['reflectionextension::__construct'] = new Call\ReflectionExtensionConstruct();
        $this->functionProxies['reflectionextension::getname'] = new Call\ReflectionExtensionGetName();
        // Thin AOT: unbound getVersion → NULL (#34016); VM uses VmReflection::reflectionExtensionVersion.
        $this->functionProxies['reflectionextension::getversion'] = new Call\ReflectionExtensionGetVersion();
        // Thin AOT: unbound getClassNames → NULL (#34150); VM #22247 / peer name-list #34110.
        $this->functionProxies['reflectionextension::getclassnames'] = new Call\ReflectionExtensionGetClassNames();
        // Thin AOT: unbound getClasses → NULL (#34169); VM #18326 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getclasses'] = new Call\ReflectionExtensionGetClasses();
        // Thin AOT: unbound getFunctions → NULL (#34177); VM #18326 / peer getClasses #34169.
        $this->functionProxies['reflectionextension::getfunctions'] = new Call\ReflectionExtensionGetFunctions();
        // Thin AOT: unbound isPersistent/isTemporary → NULL (#34154); VM #22247.
        $this->functionProxies['reflectionextension::ispersistent'] = new Call\ReflectionExtensionIsPersistent();
        $this->functionProxies['reflectionextension::istemporary'] = new Call\ReflectionExtensionIsTemporary();
        // Thin AOT: unbound getDependencies → NULL (#34155); VM #22247 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getdependencies'] = new Call\ReflectionExtensionGetDependencies();
        // Thin AOT: unbound getConstants → NULL (#34162); VM #18326 / peer getDependencies #34155.
        $this->functionProxies['reflectionextension::getconstants'] = new Call\ReflectionExtensionGetConstants();
        // Thin AOT: unbound getINIEntries → NULL (#34165); VM #22247 / peer getConstants #34162.
        $this->functionProxies['reflectionextension::getinientries'] = new Call\ReflectionExtensionGetINIEntries();
        // Thin AOT: unbound __toString/info → cast fatal / empty info (#34181); VM #22247.
        $this->functionProxies['reflectionextension::__tostring'] = new Call\ReflectionExtensionToString();
        $this->functionProxies['reflectionextension::info'] = new Call\ReflectionExtensionInfo();

        $this->functionProxies['reflectionfunction::isvariadic'] = new Call\ReflectionFunctionIsVariadic();
        // Thin AOT: unbound getNumberOfParameters / isUserDefined / isInternal → NULL (#34218).
        $this->functionProxies['reflectionfunction::getnumberofparameters'] = new Call\ReflectionFunctionGetNumberOfParameters();
        $this->functionProxies['reflectionfunction::getnumberofrequiredparameters'] = new Call\ReflectionFunctionGetNumberOfRequiredParameters();
        $this->functionProxies['reflectionfunction::getparameters'] = new Call\ReflectionFunctionGetParameters();
        $this->functionProxies['reflectionfunction::getreturntype'] = new Call\ReflectionFunctionGetReturnType();
        $this->functionProxies['reflectionfunction::hasreturntype'] = new Call\ReflectionFunctionHasReturnType();
        $this->functionProxies['reflectionfunction::isuserdefined'] = new Call\ReflectionFunctionIsUserDefined();
        $this->functionProxies['reflectionfunction::isinternal'] = new Call\ReflectionFunctionIsInternal();
        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $this->functionProxies['reflectionparameter::issensitiveparameter'] = new Call\ReflectionParameterIsSensitiveParameter();
        }
        $this->functionProxies['reflectionparameter::isvariadic'] = new Call\ReflectionParameterIsVariadic();
        $this->functionProxies['reflectionparameter::isoptional'] = new Call\ReflectionParameterIsOptional();
        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $this->functionProxies['reflectionfunction::getnamedarguments'] = new Call\ReflectionFunctionGetNamedArguments();
            $this->functionProxies['reflectionmethod::getnamedarguments'] = new Call\ReflectionMethodGetNamedArguments();
        }
        $this->functionProxies['reflectionattribute::getname'] = new Call\ReflectionAttributeGetName();
        $this->functionProxies['reflectionattribute::gettarget'] = new Call\ReflectionAttributeGetTarget();
        $this->functionProxies['reflectionattribute::newinstance'] = new Call\ReflectionAttributeNewInstance();
        $this->functionProxies['reflectionenum::__construct'] = new Call\ReflectionEnumConstruct();
        $this->functionProxies['reflectionenum::getname'] = new Call\ReflectionEnumGetName();
        $this->functionProxies['reflectionenum::hascase'] = new Call\ReflectionEnumHasCase();
        $this->functionProxies['reflectionenum::getcase'] = new Call\ReflectionEnumGetCase();
        $this->functionProxies['reflectionenum::getcases'] = new Call\ReflectionEnumGetCases();
        $this->functionProxies['reflectionenum::isbacked'] = new Call\ReflectionEnumIsBacked();
        $this->functionProxies['reflectionenum::getbackingtype'] = new Call\ReflectionEnumGetBackingType();
        $this->functionProxies['reflectionenumunitcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $this->functionProxies['reflectionenumbackedcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $unitCaseGetValue = new Call\ReflectionEnumUnitCaseGetValue();
        $this->functionProxies['reflectionenumunitcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionenumbackedcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionnamedtype::getname'] = new Call\ReflectionNamedTypeGetName();
        $this->functionProxies['reflectionnamedtype::__tostring'] = new Call\ReflectionNamedTypeToString();
        $this->functionProxies['reflectionuniontype::__tostring'] = new Call\ReflectionUnionTypeToString();
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage('Exception');
        $this->functionProxies['exception::getcode'] = new Call\ExceptionGetCode('Exception');
        $exceptionToString = new Call\ExceptionToString();
        $exceptionGetTrace = new Call\ExceptionGetTrace('Exception');
        $exceptionGetTraceAsString = new Call\ExceptionGetTraceAsString('Exception');
        $exceptionGetFile = new Call\ExceptionGetFile('Exception');
        $exceptionGetLine = new Call\ExceptionGetLine('Exception');
        $exceptionGetPrevious = new Call\ExceptionGetPrevious('Exception');
        $this->functionProxies['exception::__tostring'] = $exceptionToString;
        $this->functionProxies['exception::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['exception::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['exception::getfile'] = $exceptionGetFile;
        $this->functionProxies['exception::getline'] = $exceptionGetLine;
        $this->functionProxies['exception::getprevious'] = $exceptionGetPrevious;
        // catch (Throwable $e) resolves methods on the interface name (#27333).
        $this->functionProxies['throwable::__tostring'] = $exceptionToString;
        $this->functionProxies['throwable::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['throwable::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['throwable::getmessage'] = $this->functionProxies['exception::getmessage'];
        $this->functionProxies['throwable::getcode'] = $this->functionProxies['exception::getcode'];
        $this->functionProxies['throwable::getfile'] = $exceptionGetFile;
        $this->functionProxies['throwable::getline'] = $exceptionGetLine;
        $this->functionProxies['throwable::getprevious'] = $exceptionGetPrevious;
        // Per-class ctor so TypeError wire text + $previous arg index match Zend (#28798).
        foreach (\PHPCompiler\ext\standard\ThrowableManifest::registrationOrder() as $throwableName) {
            if (!\PHPCompiler\ext\standard\ThrowableManifest::isAdvertised($throwableName)) {
                continue;
            }
            $lc = \PHPCompiler\ext\standard\ThrowableManifest::lcKey($throwableName);
            // ErrorException::__construct(..., $previous) is Argument #6; others #3.
            $prevArg = 'errorexception' === $lc ? 6 : 3;
            $this->functionProxies[$lc.'::__construct'] = new Call\ExceptionConstruct(
                $throwableName,
                $prevArg
            );
            $isErrorFamily = \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $lc
                || \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                    $lc,
                    \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                );
            // Throwable::__toString / getTrace / get* — user-script AOT (#26796, #27333, #30895).
            $this->functionProxies[$lc.'::__tostring'] = $exceptionToString;
            $this->functionProxies[$lc.'::gettrace'] = $isErrorFamily
                ? new Call\ExceptionGetTrace('Error')
                : $exceptionGetTrace;
            $this->functionProxies[$lc.'::gettraceasstring'] = $isErrorFamily
                ? new Call\ExceptionGetTraceAsString('Error')
                : $exceptionGetTraceAsString;
            $this->functionProxies[$lc.'::getmessage'] = $isErrorFamily
                ? new Call\ExceptionGetMessage('Error')
                : $this->functionProxies['exception::getmessage'];
            $this->functionProxies[$lc.'::getcode'] = $isErrorFamily
                ? new Call\ExceptionGetCode('Error')
                : $this->functionProxies['exception::getcode'];
            $this->functionProxies[$lc.'::getfile'] = $isErrorFamily
                ? new Call\ExceptionGetFile('Error')
                : $exceptionGetFile;
            $this->functionProxies[$lc.'::getline'] = $isErrorFamily
                ? new Call\ExceptionGetLine('Error')
                : $exceptionGetLine;
            $this->functionProxies[$lc.'::getprevious'] = $isErrorFamily
                ? new Call\ExceptionGetPrevious('Error')
                : $exceptionGetPrevious;
        }
        // Alias get* for Error family roots (same prop layout; Error ACE label #30895).
        $this->functionProxies['error::getmessage'] = new Call\ExceptionGetMessage('Error');
        $this->functionProxies['error::getcode'] = new Call\ExceptionGetCode('Error');
        $this->functionProxies['error::__tostring'] = $exceptionToString;
        $this->functionProxies['error::gettrace'] = new Call\ExceptionGetTrace('Error');
        $this->functionProxies['error::gettraceasstring'] = new Call\ExceptionGetTraceAsString('Error');
        $this->functionProxies['error::getfile'] = new Call\ExceptionGetFile('Error');
        $this->functionProxies['error::getline'] = new Call\ExceptionGetLine('Error');
        $this->functionProxies['error::getprevious'] = new Call\ExceptionGetPrevious('Error');

        FiberHelper::registerJitMethods($this);
        GeneratorHelper::registerJitMethods($this);
        ClosureBindHelper::registerJitMethods($this);
        // DateTime / DateInterval / DatePeriod ctors — thin user-script AOT (#26772).
        $this->functionProxies['datetime::__construct'] = new Call\DateTimeConstruct();
        $this->functionProxies['datetimeimmutable::__construct'] = new Call\DateTimeImmutableConstruct();
        // DOMDocument / Dom\ living factories: ext/dom/Module::jitInit (#36204 / #33607).
        // ZipArchive / SQLite3 thin-AOT Call proxies: registered by ext/zip + ext/sqlite3 Module::jitInit (#36204).
        $this->functionProxies['datetimezone::__construct'] = new Call\DateTimeZoneConstruct();
        $this->functionProxies['dateinterval::__construct'] = new Call\DateIntervalConstruct();
        $this->functionProxies['dateinterval::format'] = new Call\DateIntervalFormat();
        $this->functionProxies['dateperiod::__construct'] = new Call\DatePeriodConstruct();
        if (CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            $this->functionProxies['dateperiod::createfromiso8601string'] = new Call\DatePeriodCreateFromISO8601String();
            foreach (['rewind', 'valid', 'current', 'key', 'next'] as $dpIterMethod) {
                $this->functionProxies['dateperiod::'.$dpIterMethod] = new Call\DatePeriodIteratorMethod($dpIterMethod);
            }
        }
        // Accessors — always (not gated on ISO8601); avoid ExternalMethod null stubs (#27572).
        foreach (['getstartdate', 'getenddate', 'getdateinterval', 'getrecurrences'] as $dpAcc) {
            $this->functionProxies['dateperiod::'.$dpAcc] = new Call\DatePeriodAccessorMethod($dpAcc);
        }
        $this->functionProxies['datetime::format'] = new Call\DateTimeFormat();
        $this->functionProxies['datetimeimmutable::format'] = new Call\DateTimeFormat();
        // Wire class static factories to date_create*_from_format JIT (#26788 / #6172).
        $this->functionProxies['datetime::createfromformat'] = new Call\DateTimeCreateFromFormat(false);
        $this->functionProxies['datetimeimmutable::createfromformat'] = new Call\DateTimeCreateFromFormat(true);
        // Conversion factories — avoid ExternalMethod null / segfault on thin AOT (#30762).
        $this->functionProxies['datetime::createfrominterface'] = new Call\DateTimeCreateFromInterface(false);
        $this->functionProxies['datetimeimmutable::createfrominterface'] = new Call\DateTimeCreateFromInterface(true);
        $this->functionProxies['datetime::createfromimmutable'] = new Call\DateTimeCreateFromImmutable();
        $this->functionProxies['datetimeimmutable::createfrommutable'] = new Call\DateTimeImmutableCreateFromMutable();
        // PHP 8.4+ createFromTimestamp — avoid ExternalMethod null stub abort on thin AOT (#26936).
        if (CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            $this->functionProxies['datetime::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(false);
            $this->functionProxies['datetimeimmutable::createfromtimestamp'] = new Call\DateTimeCreateFromTimestamp(true);
        }
        // PHP 8.4+ get/setMicrosecond — avoid ExternalMethod silent NULL on thin AOT (#26938).
        if (CompilerVersion::supportsDateTimeMicrosecond()) {
            $this->functionProxies['datetime::getmicrosecond'] = new Call\DateTimeGetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::getmicrosecond'] = new Call\DateTimeGetMicrosecond(true);
            $this->functionProxies['datetime::setmicrosecond'] = new Call\DateTimeSetMicrosecond(false);
            $this->functionProxies['datetimeimmutable::setmicrosecond'] = new Call\DateTimeSetMicrosecond(true);
        }
        // php-src stub $datetime — InternalArgInfo still says time (#24589).
        $this->functionProxies['dateinterval::createfromdatestring'] = new Call\DateIntervalCreateFromDateString();
        // Mutable setTimezone — thin user-script AOT property write (#22824).
        $this->functionProxies['datetime::settimezone'] = new Call\DateTimeSetTimezone(false);
        $this->functionProxies['datetime::gettimezone'] = new Call\DateTimeGetTimezone();
        $this->functionProxies['datetimeimmutable::gettimezone'] = new Call\DateTimeGetTimezone();
        // getOffset lives in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub / invokeJitCall TypeError on thin AOT (#30761).
        $this->functionProxies['datetime::getoffset'] = new Call\DateTimeGetOffset();
        $this->functionProxies['datetimeimmutable::getoffset'] = new Call\DateTimeGetOffset();
        // Immutable: allocate+copy (not cloneObject) for MCJIT. Thin user-script AOT still
        // hits "basic block has no parent" inside Object_::allocate / NestedJIT ensureLinked
        // (same as `new DateTimeImmutable` under HELPER_RUNTIME_O=0). VM Builtin covers
        // php bin/vm.php; register proxy for MCJIT / full-init only (#22824).
        if (!UserScriptAotEnv::isActive()) {
            $this->functionProxies['datetimeimmutable::settimezone'] = new Call\DateTimeSetTimezone(true);
        }
        // getTimestamp / setTimestamp live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30745).
        $this->functionProxies['datetime::gettimestamp'] = new Call\DateTimeGetTimestamp(false);
        $this->functionProxies['datetimeimmutable::gettimestamp'] = new Call\DateTimeGetTimestamp(true);
        $this->functionProxies['datetime::settimestamp'] = new Call\DateTimeSetTimestamp(false);
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772 / modify).
        $this->functionProxies['datetimeimmutable::settimestamp'] = new Call\DateTimeSetTimestamp(true);
        // setDate / setTime live in DateTimeSetTimezone.php (always loaded above).
        // Avoid ExternalMethod null stub on thin AOT (#30747).
        $this->functionProxies['datetime::setdate'] = new Call\DateTimeSetDate(false);
        $this->functionProxies['datetimeimmutable::setdate'] = new Call\DateTimeSetDate(true);
        $this->functionProxies['datetime::settime'] = new Call\DateTimeSetTime(false);
        $this->functionProxies['datetimeimmutable::settime'] = new Call\DateTimeSetTime(true);
        $this->functionProxies['datetime::setisodate'] = new Call\DateTimeSetISODate(false);
        $this->functionProxies['datetimeimmutable::setisodate'] = new Call\DateTimeSetISODate(true);
        // getLastErrors() — compile-time last-errors bag (peer date_get_last_errors) (#30749).
        $this->functionProxies['datetime::getlasterrors'] = new Call\DateTimeGetLastErrors(false);
        $this->functionProxies['datetimeimmutable::getlasterrors'] = new Call\DateTimeGetLastErrors(true);
        // modify() — avoid ExternalMethod null stub segfault after chained format() (#26789).
        // Immutable allocate+copy is thin-AOT-safe after DatePeriod foreach (#26772).
        $this->functionProxies['datetime::modify'] = new Call\DateTimeModify(false);
        $this->functionProxies['datetimeimmutable::modify'] = new Call\DateTimeModify(true);
        // add()/sub() — avoid ExternalMethod null stub segfault / silent no-op (#30760).
        // DateTimeAdd/DateTimeSub live in DateTimeModify.php (always loaded above).
        $this->functionProxies['datetime::add'] = new Call\DateTimeAdd(false);
        $this->functionProxies['datetimeimmutable::add'] = new Call\DateTimeAdd(true);
        $this->functionProxies['datetime::sub'] = new Call\DateTimeSub(false);
        $this->functionProxies['datetimeimmutable::sub'] = new Call\DateTimeSub(true);
        // Procedural date_add/date_sub — Call proxy avoids Internal FUNCCALL prep SIGSEGV (#33781).
        $this->functionProxies['date_add'] = new Call\ProceduralDateAdd();
        $this->functionProxies['date_sub'] = new Call\ProceduralDateSub();
        // DateTime::diff — compile-time DateInterval materialize (#27309).
        $this->functionProxies['datetime::diff'] = new Call\DateTimeDiff();
        $this->functionProxies['datetimeimmutable::diff'] = new Call\DateTimeDiff();
        // DateTimeZone::getTransitions — compile-time materialize (peer timezone_transitions_get) (#26799).
        $this->functionProxies['datetimezone::gettransitions'] = new Call\DateTimeZoneGetTransitions();
        // DateTimeZone::getName — avoid ExternalMethod silent NULL on thin AOT (#27307).
        $this->functionProxies['datetimezone::getname'] = new Call\DateTimeZoneGetName();
        // php-src zim_DateTimeZone_getLocation — thin AOT was silent NULL (#33727).
        $this->functionProxies['datetimezone::getlocation'] = new Call\DateTimeZoneGetLocation();
        // DateTimeZone::getOffset — avoid ExternalMethod silent NULL on thin AOT (#27308).
        $this->functionProxies['datetimezone::getoffset'] = new Call\DateTimeZoneGetOffset();
        // DateTimeZone::listIdentifiers — avoid ExternalMethod silent NULL on thin AOT (#29735).
        $this->functionProxies['datetimezone::listidentifiers'] = new Call\DateTimeZoneListIdentifiers();
        // DateTimeZone::listAbbreviations — avoid ExternalMethod silent NULL on thin AOT (#30780).
        $this->functionProxies['datetimezone::listabbreviations'] = new Call\DateTimeZoneListAbbreviations();
        // Locale::* + NumberFormatter / IntlDateFormatter / Collator / Normalizer /
        // MessageFormatter / Transliterator thin-AOT Call proxies:
        // registered by ext/intl/Module::jitInit (#36204 / #20760 / #27385 / #27361 / #28649 / #28654 / #28655 / #28657).
        // finfo::__construct / finfo::file / finfo::buffer / finfo::set_flags — thin AOT (#27196, #28660, #34688).
        $this->functionProxies['finfo::__construct'] = new Call\FinfoConstruct();
        $this->functionProxies['finfo::file'] = new Call\FinfoFile();
        $this->functionProxies['finfo::buffer'] = new Call\FinfoBuffer();
        $this->functionProxies['finfo::set_flags'] = new Call\FinfoSetFlags();
        // PDO — avoid ExternalMethod silent NULL / fake connect (#27619).
        $this->functionProxies['pdo::__construct'] = new Call\PdoConstruct();
        $this->functionProxies['pdo::getavailabledrivers'] = new Call\PdoGetAvailableDrivers();
        $this->functionProxies['pdo::quote'] = new Call\PdoQuote();
        // Dom\XMLDocument / Dom\HTMLDocument::createFromString / createFromFile +
        // DOMDocument::__construct: ext/dom/Module::jitInit (#36204 / #27108, #27300, #33607).
        // XMLReader::XML / fromString / read — avoid ExternalMethod silent NULL on thin AOT (#27299, #28670).
        // XML() exists on all profiles; fromString is PROFILE≥8.4 only.
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::xml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::open');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::read');
        // leftover of fromString read (#35908 / #27299) — php-src readInnerXml / readOuterXml
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readinnerxml');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readouterxml');
        // leftover of fromString/readInnerXml (#35917 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::readstring');
        // leftover of fromString/open (#35911 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::expand');
        // leftover of fromString/read (#35926 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::next');
        // leftover of fromString/read (#35959 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::isvalid');
        // leftover of fromString/read (#35965 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setparserproperty');
        // leftover of fromString (#35971 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschema');
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::setrelaxngschemasource');
        // leftover of fromString (#35935 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::close');
        // leftover of getAttribute (#35941 / #35918 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattribute');
        // leftover of moveToAttribute (#35946 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributeno');
        // leftover of moveToAttribute (#35948 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetofirstattribute');
        // leftover of moveToAttribute (#35951 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoattributens');
        // leftover of moveToAttribute (#35940 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetoelement');
        // leftover of moveToAttribute (#35952 / #35941 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::movetonextattribute');
        // leftover of fromString/read (#35962 / #27299)
        XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::getparserproperty');
        if (CompilerVersion::supportsXmlReaderFactories()) {
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstring');
            // leftover of fromString (#35900 / #27299)
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromuri');
            XmlReaderInstanceMethodJit::ensureProxy($this, 'xmlreader::fromstream');
        }
        // XMLWriter::toMemory / toUri / toStream — leftover of openMemory/openUri (#19606 / #35872 / #35895).
        if (CompilerVersion::supportsXmlWriterFactories()) {
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tomemory');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::touri');
            XmlWriterInstanceMethodJit::ensureProxy($this, 'xmlwriter::tostream');
        }
        if (CompilerVersion::supportsDomTokenList()) {
            DomInstanceMethodJit::registerKnownProxies($this);
        }
    }
}
