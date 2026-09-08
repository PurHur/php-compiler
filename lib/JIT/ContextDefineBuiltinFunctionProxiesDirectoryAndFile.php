<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Directory / DirectoryIterator / FilesystemIterator / RecursiveDirectoryIterator /
 * SplFileInfo / SplFileObject / SplTempFileObject / GlobIterator thin-AOT Call
 * proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the filesystem
 * SPL catalog stays a separate TU from iterator / SplFixedArray / WeakMap wiring
 * (split-TU / size-budget ratchet toward ContextDefineBuiltinFunctionProxies
 * ≤ 300 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesDirectoryAndFile;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * before SplHeap / SplPriorityQueue / SplDllist registration.
 *
 * No new C ABI. php-src analogy: zim_DirectoryIterator_* / zim_SplFileObject_*
 * method tables live in ext/spl/spl_directory.c beside the executor rather than
 * inside a monolithic MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesDirectoryAndFile
{
    private function defineBuiltinFunctionProxiesDirectoryAndFile(): void
    {
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
    }
}
