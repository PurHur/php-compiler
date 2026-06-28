<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;

class Type extends Builtin {

    public Type\String_ $string;
    public Type\Object_ $object;
    public Type\Value $value;
    public Type\HashTable $hashtable;
    protected array $fields;

    public function register(): void {
        // Construct Value/HashTable before String_ so nested JIT helpers in String_::implement()
        // see __value__/__hashtable__ LLVM bodies (#12910).
        $this->value = new Type\Value($this->context, $this->loadType);
        $this->hashtable = new Type\HashTable($this->context, $this->loadType);
        $this->object = new Type\Object_($this->context, $this->loadType);
        $this->string = new Type\String_($this->context, $this->loadType);
        // $this->maskedarray = new Type\MaskedArray($this->context, $this->loadType);
        // $this->nativearray = new Type\NativeArray($this->context, $this->loadType);
        $this->string->register();
        $this->value->register();
        $this->object->register();
        $this->hashtable->register();
        $i8 = $this->context->getTypeFromString('int8');
        $fntypeGetenv = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $i8,
            $this->context->getTypeFromString('__value__*')
        );
        $fnGetenv = $this->context->module->addFunction('__compiler_getenv', $fntypeGetenv);
        $this->context->registerFunction('__compiler_getenv', $fnGetenv);
        $fntypeGetenvAll = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__value__*')
        );
        $fnGetenvAll = $this->context->module->addFunction('__compiler_getenv_all', $fntypeGetenvAll);
        $this->context->registerFunction('__compiler_getenv_all', $fnGetenvAll);
        $fntypeDeployPath = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnDeployPath = $this->context->module->addFunction(
            '__compiler_phpc_deploy_path',
            $fntypeDeployPath
        );
        $this->context->registerFunction('__compiler_phpc_deploy_path', $fnDeployPath);
        $fntypeNumberFormat = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('double'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnNumberFormat = $this->context->module->addFunction('__compiler_number_format', $fntypeNumberFormat);
        $this->context->registerFunction('__compiler_number_format', $fnNumberFormat);
        $f64 = $this->context->getTypeFromString('double');
        $fntypeSprintf = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnSprintf = $this->context->module->addFunction('__compiler_sprintf', $fntypeSprintf);
        $this->context->registerFunction('__compiler_sprintf', $fnSprintf);
        $fntypePrintf = $this->context->context->functionType(
            $this->context->getTypeFromString('int64'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnPrintf = $this->context->module->addFunction('__compiler_printf', $fntypePrintf);
        $this->context->registerFunction('__compiler_printf', $fnPrintf);
        $fntypeSscanf = $this->context->context->functionType(
            $this->context->getTypeFromString('int64'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__**')
        );
        $fnSscanf = $this->context->module->addFunction('__compiler_sscanf', $fntypeSscanf);
        $this->context->registerFunction('__compiler_sscanf', $fnSscanf);
        $fntypeSscanfArray = $this->context->context->functionType(
            $this->context->getTypeFromString('__value__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnSscanfArray = $this->context->module->addFunction('__compiler_sscanf_array', $fntypeSscanfArray);
        $this->context->registerFunction('__compiler_sscanf_array', $fnSscanfArray);
        $fntypeSscanfEx = $this->context->context->functionType(
            $this->context->getTypeFromString('int64'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__**'),
            $this->context->getTypeFromString('size_t*')
        );
        $fnSscanfEx = $this->context->module->addFunction('__compiler_sscanf_ex', $fntypeSscanfEx);
        $this->context->registerFunction('__compiler_sscanf_ex', $fnSscanfEx);
        $fntypeVfscanf = $this->context->context->functionType(
            $this->context->getTypeFromString('int64'),
            false,
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__**')
        );
        $fnVfscanf = $this->context->module->addFunction('__compiler_vfscanf', $fntypeVfscanf);
        $this->context->registerFunction('__compiler_vfscanf', $fnVfscanf);
        $fntypePack = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnPack = $this->context->module->addFunction('__compiler_pack', $fntypePack);
        $this->context->registerFunction('__compiler_pack', $fnPack);
        $fntypeUnpack = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnUnpack = $this->context->module->addFunction('__compiler_unpack', $fntypeUnpack);
        $this->context->registerFunction('__compiler_unpack', $fnUnpack);
        $fntypeVarExport = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__value__*')
        );
        $fnVarExport = $this->context->module->addFunction('__compiler_var_export', $fntypeVarExport);
        $this->context->registerFunction('__compiler_var_export', $fnVarExport);
        $fntypePrintR = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__value__*')
        );
        $fnPrintR = $this->context->module->addFunction('__compiler_print_r', $fntypePrintR);
        $this->context->registerFunction('__compiler_print_r', $fnPrintR);
        $fntypeVarDump = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__value__*')
        );
        $fnVarDump = $this->context->module->addFunction('__compiler_var_dump', $fntypeVarDump);
        $this->context->registerFunction('__compiler_var_dump', $fnVarDump);
        $fntypeIniSet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnIniSet = $this->context->module->addFunction('__compiler_ini_set', $fntypeIniSet);
        $this->context->registerFunction('__compiler_ini_set', $fnIniSet);
        $fntypeIniGet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnIniGet = $this->context->module->addFunction('__compiler_ini_get', $fntypeIniGet);
        $this->context->registerFunction('__compiler_ini_get', $fnIniGet);
        $fnIniCfgGet = $this->context->module->addFunction('__compiler_ini_cfg_get', $fntypeIniGet);
        $this->context->registerFunction('__compiler_ini_cfg_get', $fnIniCfgGet);
        $fntypeIniRestore = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnIniRestore = $this->context->module->addFunction('__compiler_ini_restore', $fntypeIniRestore);
        $this->context->registerFunction('__compiler_ini_restore', $fnIniRestore);
        $fntypeErrorReporting = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('int32'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnErrorReporting = $this->context->module->addFunction('__compiler_error_reporting', $fntypeErrorReporting);
        $this->context->registerFunction('__compiler_error_reporting', $fnErrorReporting);
        $fntypeSilence = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false
        );
        $fnBeginSilence = $this->context->module->addFunction('__compiler_begin_silence', $fntypeSilence);
        $this->context->registerFunction('__compiler_begin_silence', $fnBeginSilence);
        $fnEndSilence = $this->context->module->addFunction('__compiler_end_silence', $fntypeSilence);
        $this->context->registerFunction('__compiler_end_silence', $fnEndSilence);
        $fntypeStripTags = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnStripTags = $this->context->module->addFunction('__compiler_strip_tags', $fntypeStripTags);
        $this->context->registerFunction('__compiler_strip_tags', $fnStripTags);
        $i64 = $this->context->getTypeFromString('int64');
        $fntypeUtf8Strlen = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnUtf8Strlen = $this->context->module->addFunction('__compiler_utf8_strlen', $fntypeUtf8Strlen);
        $this->context->registerFunction('__compiler_utf8_strlen', $fnUtf8Strlen);
        $fnUtf8Valid = $this->context->module->addFunction('__compiler_utf8_valid', $fntypeUtf8Strlen);
        $this->context->registerFunction('__compiler_utf8_valid', $fnUtf8Valid);
        $i8p = $this->context->getTypeFromString('int8*');
        $i32 = $this->context->getTypeFromString('int32');
        $sizeT = $this->context->getTypeFromString('size_t');
        $voidTy = $this->context->getTypeFromString('void');
        foreach (
            [
                'getenv' => [$i8p, false, $i8p],
                'putenv' => [$i32, false, $i8p],
                'strlen' => [$sizeT, false, $i8p],
                '__compiler_env_local_lookup' => [$i8p, false, $i8p],
                '__compiler_env_register_putenv' => [$voidTy, false, $i8p],
            ] as $libcName => [$ret, $vararg, $param]
        ) {
            $this->ensureExternalFunction($libcName, $this->context->context->functionType($ret, $vararg, $param));
        }
        $this->ensureExternalFunction('open', $this->context->context->functionType($i32, false, $i8p, $i32, $i32));
        $this->ensureExternalFunction('fopen', $this->context->context->functionType($i8p, false, $i8p, $i8p));
        $this->ensureExternalFunction('fwrite', $this->context->context->functionType($sizeT, false, $i8p, $sizeT, $sizeT, $i8p));
        $this->ensureExternalFunction('fclose', $this->context->context->functionType($i32, false, $i8p));
        $this->ensureExternalFunction('read', $this->context->context->functionType($i64, false, $i32, $i8p, $sizeT));
        $this->ensureExternalFunction('lseek', $this->context->context->functionType($i64, false, $i32, $i64, $i32));
        $this->ensureExternalFunction('write', $this->context->context->functionType($i64, false, $i32, $i8p, $sizeT));
        $this->ensureExternalFunction('close', $this->context->context->functionType($i32, false, $i32));
        $fntypeReadfile = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnReadfile = $this->context->module->addFunction('__compiler_readfile', $fntypeReadfile);
        $this->context->registerFunction('__compiler_readfile', $fnReadfile);
        $fntypeFileGetContents = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnFileGetContents = $this->context->module->addFunction(
            '__compiler_file_get_contents',
            $fntypeFileGetContents
        );
        $this->context->registerFunction('__compiler_file_get_contents', $fnFileGetContents);
        $fntypeIncludePathGet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__value__*')
        );
        $fnIncludePathGet = $this->context->module->addFunction(
            '__compiler_get_include_path',
            $fntypeIncludePathGet
        );
        $this->context->registerFunction('__compiler_get_include_path', $fnIncludePathGet);
        $fntypeIncludePathSet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnIncludePathSet = $this->context->module->addFunction(
            '__compiler_set_include_path',
            $fntypeIncludePathSet
        );
        $this->context->registerFunction('__compiler_set_include_path', $fnIncludePathSet);
        $fntypeIncludePathRestore = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false
        );
        $fnIncludePathRestore = $this->context->module->addFunction(
            '__compiler_restore_include_path',
            $fntypeIncludePathRestore
        );
        $this->context->registerFunction('__compiler_restore_include_path', $fnIncludePathRestore);
        $fnStreamResolveIncludePath = $this->context->module->addFunction(
            '__compiler_stream_resolve_include_path',
            $fntypeFileGetContents
        );
        $this->context->registerFunction('__compiler_stream_resolve_include_path', $fnStreamResolveIncludePath);
        $fntypeIncludePathInit = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false
        );
        $fnIncludePathInit = $this->context->module->addFunction(
            '__compiler_include_path_init',
            $fntypeIncludePathInit
        );
        $this->context->registerFunction('__compiler_include_path_init', $fnIncludePathInit);
        $fntypeMimeContentType = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnMimeContentType = $this->context->module->addFunction(
            '__compiler_mime_content_type',
            $fntypeMimeContentType
        );
        $this->context->registerFunction('__compiler_mime_content_type', $fnMimeContentType);
        $fntypeGetMetaTags = $this->context->context->functionType(
            $this->context->getTypeFromString('__hashtable__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int1')
        );
        $fnGetMetaTags = $this->context->module->addFunction(
            '__compiler_get_meta_tags',
            $fntypeGetMetaTags
        );
        $this->context->registerFunction('__compiler_get_meta_tags', $fnGetMetaTags);
        $fntypeFilePutContents = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnFilePutContents = $this->context->module->addFunction(
            '__compiler_file_put_contents',
            $fntypeFilePutContents
        );
        $this->context->registerFunction('__compiler_file_put_contents', $fnFilePutContents);
        $fntypeFwrite = $this->context->context->functionType(
            $i64,
            false,
            $i64,
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnCompilerFwrite = $this->context->module->addFunction('__compiler_fwrite', $fntypeFwrite);
        $this->context->registerFunction('__compiler_fwrite', $fnCompilerFwrite);
        $fntypeErrorLog = $this->context->context->functionType(
            $this->context->getTypeFromString('int1'),
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnErrorLog = $this->context->module->addFunction('__compiler_error_log', $fntypeErrorLog);
        $this->context->registerFunction('__compiler_error_log', $fnErrorLog);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $fntypeFopen = $this->context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr
        );
        $fnFopen = $this->context->module->addFunction('__compiler_fopen', $fntypeFopen);
        $this->context->registerFunction('__compiler_fopen', $fnFopen);
        $fntypeTmpfile = $this->context->context->functionType($i64, false);
        $fnTmpfile = $this->context->module->addFunction('__compiler_tmpfile', $fntypeTmpfile);
        $this->context->registerFunction('__compiler_tmpfile', $fnTmpfile);
        $fntypeFread = $this->context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64
        );
        $fnFread = $this->context->module->addFunction('__compiler_fread', $fntypeFread);
        $this->context->registerFunction('__compiler_fread', $fnFread);
        $fntypeFclose = $this->context->context->functionType($i32, false, $i64);
        $fnFclose = $this->context->module->addFunction('__compiler_fclose', $fntypeFclose);
        $this->context->registerFunction('__compiler_fclose', $fnFclose);
        $fntypePopen = $this->context->context->functionType($i64, false, $strPtr, $strPtr);
        $fnPopen = $this->context->module->addFunction('__compiler_popen', $fntypePopen);
        $this->context->registerFunction('__compiler_popen', $fnPopen);
        $fntypePclose = $this->context->context->functionType($i32, false, $i64);
        $fnPclose = $this->context->module->addFunction('__compiler_pclose', $fntypePclose);
        $this->context->registerFunction('__compiler_pclose', $fnPclose);
        $fntypeOpendir = $this->context->context->functionType($i64, false, $strPtr);
        $fnOpendir = $this->context->module->addFunction('__compiler_opendir', $fntypeOpendir);
        $this->context->registerFunction('__compiler_opendir', $fnOpendir);
        $fntypeReaddir = $this->context->context->functionType($strPtr, false, $i64);
        $fnReaddir = $this->context->module->addFunction('__compiler_readdir', $fntypeReaddir);
        $this->context->registerFunction('__compiler_readdir', $fnReaddir);
        $fntypeClosedir = $this->context->context->functionType($i32, false, $i64);
        $fnClosedir = $this->context->module->addFunction('__compiler_closedir', $fntypeClosedir);
        $this->context->registerFunction('__compiler_closedir', $fnClosedir);
        $fntypeRewinddir = $this->context->context->functionType($i32, false, $i64);
        $fnRewinddir = $this->context->module->addFunction('__compiler_rewinddir', $fntypeRewinddir);
        $this->context->registerFunction('__compiler_rewinddir', $fnRewinddir);
        $fntypeIsResource = $this->context->context->functionType($i32, false, $i64);
        $fnIsResource = $this->context->module->addFunction('__compiler_is_resource', $fntypeIsResource);
        $this->context->registerFunction('__compiler_is_resource', $fnIsResource);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypeProcOpen = $this->context->context->functionType($i64, false, $strPtr, $htPtr);
        $fnProcOpen = $this->context->module->addFunction('__compiler_proc_open', $fntypeProcOpen);
        $this->context->registerFunction('__compiler_proc_open', $fnProcOpen);
        $fntypeProcClose = $this->context->context->functionType($i32, false, $i64);
        $fnProcClose = $this->context->module->addFunction('__compiler_proc_close', $fntypeProcClose);
        $this->context->registerFunction('__compiler_proc_close', $fnProcClose);
        $fntypeIsProcessResource = $this->context->context->functionType($i32, false, $i64);
        $fnIsProcessResource = $this->context->module->addFunction('__compiler_is_process_resource', $fntypeIsProcessResource);
        $this->context->registerFunction('__compiler_is_process_resource', $fnIsProcessResource);
        $fntypeGetResources = $this->context->context->functionType($htPtr, false, $strPtr);
        $fnGetResources = $this->context->module->addFunction('__compiler_get_resources', $fntypeGetResources);
        $this->context->registerFunction('__compiler_get_resources', $fnGetResources);
        $fntypeFlock = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnFlock = $this->context->module->addFunction('__compiler_flock', $fntypeFlock);
        $this->context->registerFunction('__compiler_flock', $fnFlock);
        $fntypeFpassthru = $this->context->context->functionType($i64, false, $i64);
        $fnFpassthru = $this->context->module->addFunction('__compiler_fpassthru', $fntypeFpassthru);
        $this->context->registerFunction('__compiler_fpassthru', $fnFpassthru);
        $fntypeFeof = $this->context->context->functionType($i32, false, $i64);
        $fnFeof = $this->context->module->addFunction('__compiler_feof', $fntypeFeof);
        $this->context->registerFunction('__compiler_feof', $fnFeof);
        $fntypeFflush = $this->context->context->functionType($i32, false, $i64);
        $fnFflush = $this->context->module->addFunction('__compiler_fflush', $fntypeFflush);
        $this->context->registerFunction('__compiler_fflush', $fnFflush);
        $fntypeFsync = $this->context->context->functionType($i32, false, $i64);
        $fnFsync = $this->context->module->addFunction('__compiler_fsync', $fntypeFsync);
        $this->context->registerFunction('__compiler_fsync', $fnFsync);
        $fntypeFdatasync = $this->context->context->functionType($i32, false, $i64);
        $fnFdatasync = $this->context->module->addFunction('__compiler_fdatasync', $fntypeFdatasync);
        $this->context->registerFunction('__compiler_fdatasync', $fnFdatasync);
        $fntypeStreamSetChunkSize = $this->context->context->functionType($i64, false, $i64, $i64);
        $fnStreamSetChunkSize = $this->context->module->addFunction('__compiler_stream_set_chunk_size', $fntypeStreamSetChunkSize);
        $this->context->registerFunction('__compiler_stream_set_chunk_size', $fnStreamSetChunkSize);
        $fntypeStreamSetTimeout = $this->context->context->functionType($i32, false, $i64, $i64, $i64);
        $fnStreamSetTimeout = $this->context->module->addFunction('__compiler_stream_set_timeout', $fntypeStreamSetTimeout);
        $this->context->registerFunction('__compiler_stream_set_timeout', $fnStreamSetTimeout);
        $fntypeStreamSetWriteBuffer = $this->context->context->functionType($i64, false, $i64, $i64);
        $fnStreamSetWriteBuffer = $this->context->module->addFunction('__compiler_stream_set_write_buffer', $fntypeStreamSetWriteBuffer);
        $this->context->registerFunction('__compiler_stream_set_write_buffer', $fnStreamSetWriteBuffer);
        $fntypeStreamSetReadBuffer = $this->context->context->functionType($i64, false, $i64, $i64);
        $fnStreamSetReadBuffer = $this->context->module->addFunction('__compiler_stream_set_read_buffer', $fntypeStreamSetReadBuffer);
        $this->context->registerFunction('__compiler_stream_set_read_buffer', $fnStreamSetReadBuffer);
        $fntypeStreamSupports = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnStreamSupports = $this->context->module->addFunction('__compiler_stream_supports', $fntypeStreamSupports);
        $this->context->registerFunction('__compiler_stream_supports', $fnStreamSupports);
        $fntypeStreamIsLocal = $this->context->context->functionType($i32, false, $i64);
        $fnStreamIsLocal = $this->context->module->addFunction('__compiler_stream_is_local', $fntypeStreamIsLocal);
        $this->context->registerFunction('__compiler_stream_is_local', $fnStreamIsLocal);
        $fntypeStreamIsLocalUri = $this->context->context->functionType($i32, false, $i8p);
        $fnStreamIsLocalUri = $this->context->module->addFunction('__compiler_stream_is_local_uri', $fntypeStreamIsLocalUri);
        $this->context->registerFunction('__compiler_stream_is_local_uri', $fnStreamIsLocalUri);
        $fntypeStreamIsatty = $this->context->context->functionType($i32, false, $i64);
        $fnStreamIsatty = $this->context->module->addFunction('__compiler_stream_isatty', $fntypeStreamIsatty);
        $this->context->registerFunction('__compiler_stream_isatty', $fnStreamIsatty);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypeStreamGetMetaData = $this->context->context->functionType($htPtr, false, $i64);
        $fnStreamGetMetaData = $this->context->module->addFunction('__compiler_stream_get_meta_data', $fntypeStreamGetMetaData);
        $this->context->registerFunction('__compiler_stream_get_meta_data', $fnStreamGetMetaData);
        $fntypeStreamSetBlocking = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnStreamSetBlocking = $this->context->module->addFunction('__compiler_stream_set_blocking', $fntypeStreamSetBlocking);
        $this->context->registerFunction('__compiler_stream_set_blocking', $fnStreamSetBlocking);
        $fntypeStreamSocketGetName = $this->context->context->functionType($strPtr, false, $i64, $i64);
        $fnStreamSocketGetName = $this->context->module->addFunction('__compiler_stream_socket_get_name', $fntypeStreamSocketGetName);
        $this->context->registerFunction('__compiler_stream_socket_get_name', $fnStreamSocketGetName);
        $fntypeFtruncate = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnFtruncate = $this->context->module->addFunction('__compiler_ftruncate', $fntypeFtruncate);
        $this->context->registerFunction('__compiler_ftruncate', $fnFtruncate);
        $fntypeFtell = $this->context->context->functionType($i64, false, $i64);
        $fnFtell = $this->context->module->addFunction('__compiler_ftell', $fntypeFtell);
        $this->context->registerFunction('__compiler_ftell', $fnFtell);
        $fntypeFgetc = $this->context->context->functionType($strPtr, false, $i64);
        $fnFgetc = $this->context->module->addFunction('__compiler_fgetc', $fntypeFgetc);
        $this->context->registerFunction('__compiler_fgetc', $fnFgetc);
        $fntypeFgets = $this->context->context->functionType($strPtr, false, $i64, $i64);
        $fnFgets = $this->context->module->addFunction('__compiler_fgets', $fntypeFgets);
        $this->context->registerFunction('__compiler_fgets', $fnFgets);
        $fntypeStreamGetLine = $this->context->context->functionType($strPtr, false, $i64, $i64, $strPtr);
        $fnStreamGetLine = $this->context->module->addFunction('__compiler_stream_get_line', $fntypeStreamGetLine);
        $this->context->registerFunction('__compiler_stream_get_line', $fnStreamGetLine);
        $fntypeFseek = $this->context->context->functionType($i64, false, $i64, $i64, $i64);
        $fnFseek = $this->context->module->addFunction('__compiler_fseek', $fntypeFseek);
        $this->context->registerFunction('__compiler_fseek', $fnFseek);
        $fntypeStreamGetContents = $this->context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64
        );
        $fnStreamGetContents = $this->context->module->addFunction(
            '__compiler_stream_get_contents',
            $fntypeStreamGetContents
        );
        $this->context->registerFunction('__compiler_stream_get_contents', $fnStreamGetContents);
        $fntypeStreamCopyToStream = $this->context->context->functionType(
            $i64,
            false,
            $i64,
            $i64,
            $i64,
            $i64
        );
        $fnStreamCopyToStream = $this->context->module->addFunction(
            '__compiler_stream_copy_to_stream',
            $fntypeStreamCopyToStream
        );
        $this->context->registerFunction('__compiler_stream_copy_to_stream', $fnStreamCopyToStream);
        $fntypeStreamCopyToString = $this->context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64
        );
        $fnStreamCopyToString = $this->context->module->addFunction(
            '__compiler_stream_copy_to_string',
            $fntypeStreamCopyToString
        );
        $this->context->registerFunction('__compiler_stream_copy_to_string', $fnStreamCopyToString);
        $fntypeGetResourceType = $this->context->context->functionType($strPtr, false, $i64);
        $fnGetResourceType = $this->context->module->addFunction(
            '__compiler_get_resource_type',
            $fntypeGetResourceType
        );
        $this->context->registerFunction('__compiler_get_resource_type', $fnGetResourceType);
        $fntypeMkdir = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $i32
        );
        $fnMkdir = $this->context->module->addFunction('__compiler_mkdir', $fntypeMkdir);
        $this->context->registerFunction('__compiler_mkdir', $fnMkdir);
        $fntypeCopy = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnCopy = $this->context->module->addFunction('__compiler_copy', $fntypeCopy);
        $this->context->registerFunction('__compiler_copy', $fnCopy);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $fntypeChgrp = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $valuePtr,
            $i32
        );
        $fnChgrp = $this->context->module->addFunction('__compiler_chgrp', $fntypeChgrp);
        $this->context->registerFunction('__compiler_chgrp', $fnChgrp);
        $fnChown = $this->context->module->addFunction('__compiler_chown', $fntypeChgrp);
        $this->context->registerFunction('__compiler_chown', $fnChown);
        $fntypeMoveUploaded = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnMoveUploaded = $this->context->module->addFunction(
            '__compiler_move_uploaded_file',
            $fntypeMoveUploaded
        );
        $this->context->registerFunction('__compiler_move_uploaded_file', $fnMoveUploaded);
        $fntypeIsUploaded = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnIsUploaded = $this->context->module->addFunction(
            '__compiler_is_uploaded_file',
            $fntypeIsUploaded
        );
        $this->context->registerFunction('__compiler_is_uploaded_file', $fnIsUploaded);
        $fntypeTouch = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $i64
        );
        $fnTouch = $this->context->module->addFunction('__compiler_touch', $fntypeTouch);
        $this->context->registerFunction('__compiler_touch', $fnTouch);
        $void = $this->context->getTypeFromString('void');
        $fntypeRandomBytes = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $i64
        );
        $fnRandomBytes = $this->context->module->addFunction('__compiler_random_bytes', $fntypeRandomBytes);
        $this->context->registerFunction('__compiler_random_bytes', $fnRandomBytes);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $fntypeHash = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32);
        $fnHash = $this->context->module->addFunction('__compiler_hash', $fntypeHash);
        $this->context->registerFunction('__compiler_hash', $fnHash);
        $fntypeHashHmac = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i32);
        $fnHashHmac = $this->context->module->addFunction('__compiler_hash_hmac', $fntypeHashHmac);
        $this->context->registerFunction('__compiler_hash_hmac', $fnHashHmac);
        $i64 = $this->context->getTypeFromString('int64');
        $fntypeHashPbkdf2 = $this->context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $strPtr,
            $i64,
            $i64,
            $i32
        );
        $fnHashPbkdf2 = $this->context->module->addFunction('__compiler_hash_pbkdf2', $fntypeHashPbkdf2);
        $this->context->registerFunction('__compiler_hash_pbkdf2', $fnHashPbkdf2);
        $fntypeHashHkdf = $this->context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $i64,
            $strPtr,
            $strPtr
        );
        $fnHashHkdf = $this->context->module->addFunction('__compiler_hash_hkdf', $fntypeHashHkdf);
        $this->context->registerFunction('__compiler_hash_hkdf', $fnHashHkdf);
        $fntypeHashEquals = $this->context->context->functionType($i32, false, $strPtr, $strPtr);
        $fnHashEquals = $this->context->module->addFunction('__compiler_hash_equals', $fntypeHashEquals);
        $this->context->registerFunction('__compiler_hash_equals', $fnHashEquals);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypeHashHmacAlgos = $this->context->context->functionType($htPtr, false);
        $fnHashHmacAlgos = $this->context->module->addFunction(
            '__compiler_hash_hmac_algos',
            $fntypeHashHmacAlgos
        );
        $this->context->registerFunction('__compiler_hash_hmac_algos', $fnHashHmacAlgos);
        $fnHashAlgos = $this->context->module->addFunction(
            '__compiler_hash_algos',
            $fntypeHashHmacAlgos
        );
        $this->context->registerFunction('__compiler_hash_algos', $fnHashAlgos);
        $double = $this->context->getTypeFromString('double');
        $fntypeMicrotimeStr = $this->context->context->functionType($strPtr, false);
        $fnMicrotimeStr = $this->context->module->addFunction('__compiler_microtime_string', $fntypeMicrotimeStr);
        $this->context->registerFunction('__compiler_microtime_string', $fnMicrotimeStr);
        $fntypeMicrotimeFloat = $this->context->context->functionType($double, false);
        $fnMicrotimeFloat = $this->context->module->addFunction('__compiler_microtime_float', $fntypeMicrotimeFloat);
        $this->context->registerFunction('__compiler_microtime_float', $fnMicrotimeFloat);
        $fntypePhpversion = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnPhpversion = $this->context->module->addFunction('__compiler_phpversion', $fntypePhpversion);
        $this->context->registerFunction('__compiler_phpversion', $fnPhpversion);
        $fntypePhpSapi = $this->context->context->functionType($strPtr, false);
        $fnPhpSapi = $this->context->module->addFunction('__compiler_php_sapi_name', $fntypePhpSapi);
        $this->context->registerFunction('__compiler_php_sapi_name', $fnPhpSapi);
        $fntypeZendVersion = $this->context->context->functionType($strPtr, false);
        $fnZendVersion = $this->context->module->addFunction('__compiler_zend_version', $fntypeZendVersion);
        $this->context->registerFunction('__compiler_zend_version', $fnZendVersion);
        $fntypePhpUname = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnPhpUname = $this->context->module->addFunction('__compiler_php_uname', $fntypePhpUname);
        $this->context->registerFunction('__compiler_php_uname', $fnPhpUname);
        $fntypeVersionCompare = $this->context->context->functionType($i64, false, $strPtr, $strPtr);
        $fnVersionCompare = $this->context->module->addFunction(
            '__compiler_version_compare',
            $fntypeVersionCompare
        );
        $this->context->registerFunction('__compiler_version_compare', $fnVersionCompare);
        $fntypeExtensionLoaded = $this->context->context->functionType($i32, false, $strPtr);
        $fnExtensionLoaded = $this->context->module->addFunction(
            '__compiler_extension_loaded',
            $fntypeExtensionLoaded
        );
        $this->context->registerFunction('__compiler_extension_loaded', $fnExtensionLoaded);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypeLoadedExtensions = $this->context->context->functionType($htPtr, false, $i32);
        $fnLoadedExtensions = $this->context->module->addFunction(
            '__compiler_get_loaded_extensions',
            $fntypeLoadedExtensions
        );
        $this->context->registerFunction('__compiler_get_loaded_extensions', $fnLoadedExtensions);
        $fntypeExtensionFuncs = $this->context->context->functionType($htPtr, false, $strPtr);
        $fnExtensionFuncs = $this->context->module->addFunction(
            '__compiler_get_extension_funcs',
            $fntypeExtensionFuncs
        );
        $this->context->registerFunction('__compiler_get_extension_funcs', $fnExtensionFuncs);
        $fntypeGettimeofdayArray = $this->context->context->functionType($htPtr, false);
        $fnGettimeofdayArray = $this->context->module->addFunction(
            '__compiler_gettimeofday_array',
            $fntypeGettimeofdayArray
        );
        $this->context->registerFunction('__compiler_gettimeofday_array', $fnGettimeofdayArray);
        $fntypeGettimeofdayFloat = $this->context->context->functionType($double, false);
        $fnGettimeofdayFloat = $this->context->module->addFunction(
            '__compiler_gettimeofday_float',
            $fntypeGettimeofdayFloat
        );
        $this->context->registerFunction('__compiler_gettimeofday_float', $fnGettimeofdayFloat);
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypeHrtimeNs = $this->context->context->functionType($double, false);
        $fnHrtimeNs = $this->context->module->addFunction('__compiler_hrtime_ns', $fntypeHrtimeNs);
        $this->context->registerFunction('__compiler_hrtime_ns', $fnHrtimeNs);
        $fntypeHrtimePair = $this->context->context->functionType($htPtr, false);
        $fnHrtimePair = $this->context->module->addFunction('__compiler_hrtime_pair', $fntypeHrtimePair);
        $this->context->registerFunction('__compiler_hrtime_pair', $fnHrtimePair);
        $i32 = $this->context->getTypeFromString('int32');
        $fntypeTimeNanosleep = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnTimeNanosleep = $this->context->module->addFunction(
            '__compiler_time_nanosleep',
            $fntypeTimeNanosleep
        );
        $this->context->registerFunction('__compiler_time_nanosleep', $fnTimeNanosleep);
        $fntypeTimeSleepUntil = $this->context->context->functionType($i32, false, $double);
        $fnTimeSleepUntil = $this->context->module->addFunction(
            '__compiler_time_sleep_until',
            $fntypeTimeSleepUntil
        );
        $this->context->registerFunction('__compiler_time_sleep_until', $fnTimeSleepUntil);
        $fntypePasswordHash = $this->context->context->functionType($strPtr, false, $strPtr, $i64, $i64);
        $fnPasswordHash = $this->context->module->addFunction('__compiler_password_hash', $fntypePasswordHash);
        $this->context->registerFunction('__compiler_password_hash', $fnPasswordHash);
        $fntypePasswordVerify = $this->context->context->functionType($i32, false, $strPtr, $strPtr);
        $fnPasswordVerify = $this->context->module->addFunction('__compiler_password_verify', $fntypePasswordVerify);
        $this->context->registerFunction('__compiler_password_verify', $fnPasswordVerify);
        $fntypeCrypt = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fnCrypt = $this->context->module->addFunction('__compiler_crypt', $fntypeCrypt);
        $this->context->registerFunction('__compiler_crypt', $fnCrypt);
        $fntypePasswordGetInfo = $this->context->context->functionType($htPtr, false, $strPtr);
        $fnPasswordGetInfo = $this->context->module->addFunction(
            '__compiler_password_get_info',
            $fntypePasswordGetInfo
        );
        $this->context->registerFunction('__compiler_password_get_info', $fnPasswordGetInfo);
        $fntypePasswordNeedsRehash = $this->context->context->functionType(
            $this->context->getTypeFromString('int32'),
            false,
            $strPtr,
            $i64,
            $i64
        );
        $fnPasswordNeedsRehash = $this->context->module->addFunction(
            '__compiler_password_needs_rehash',
            $fntypePasswordNeedsRehash
        );
        $this->context->registerFunction('__compiler_password_needs_rehash', $fnPasswordNeedsRehash);
        $fntypePasswordAlgos = $this->context->context->functionType($htPtr, false);
        $fnPasswordAlgos = $this->context->module->addFunction(
            '__compiler_password_algos',
            $fntypePasswordAlgos
        );
        $this->context->registerFunction('__compiler_password_algos', $fnPasswordAlgos);
        $fntypeStrtr = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fnStrtr = $this->context->module->addFunction('__compiler_strtr', $fntypeStrtr);
        $this->context->registerFunction('__compiler_strtr', $fnStrtr);
        $fntypeStrtrArray = $this->context->context->functionType($strPtr, false, $strPtr, $htPtr);
        $fnStrtrArray = $this->context->module->addFunction('__compiler_strtr_array', $fntypeStrtrArray);
        $this->context->registerFunction('__compiler_strtr_array', $fnStrtrArray);
        $fntypeUuencode = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnUuencode = $this->context->module->addFunction('__compiler_convert_uuencode', $fntypeUuencode);
        $this->context->registerFunction('__compiler_convert_uuencode', $fnUuencode);
        $fntypeQuotPrint = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnQuotEncode = $this->context->module->addFunction(
            '__compiler_quoted_printable_encode',
            $fntypeQuotPrint
        );
        $this->context->registerFunction('__compiler_quoted_printable_encode', $fnQuotEncode);
        $fnQuotDecode = $this->context->module->addFunction(
            '__compiler_quoted_printable_decode',
            $fntypeQuotPrint
        );
        $this->context->registerFunction('__compiler_quoted_printable_decode', $fnQuotDecode);
        $fntypeUtf8Latin1 = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnUtf8Encode = $this->context->module->addFunction('__compiler_utf8_encode', $fntypeUtf8Latin1);
        $this->context->registerFunction('__compiler_utf8_encode', $fnUtf8Encode);
        $fnUtf8Decode = $this->context->module->addFunction('__compiler_utf8_decode', $fntypeUtf8Latin1);
        $this->context->registerFunction('__compiler_utf8_decode', $fnUtf8Decode);
        $fntypeUudecode = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $strPtr,
            $this->context->getTypeFromString('__value__*')
        );
        $fnUudecode = $this->context->module->addFunction('__compiler_convert_uudecode', $fntypeUudecode);
        $this->context->registerFunction('__compiler_convert_uudecode', $fnUudecode);
        $fntypeAddcslashes = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fnAddcslashes = $this->context->module->addFunction('__compiler_addcslashes', $fntypeAddcslashes);
        $this->context->registerFunction('__compiler_addcslashes', $fnAddcslashes);
        $fntypeStripcslashes = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnStripcslashes = $this->context->module->addFunction('__compiler_stripcslashes', $fntypeStripcslashes);
        $this->context->registerFunction('__compiler_stripcslashes', $fnStripcslashes);
        $i32 = $this->context->getTypeFromString('int32');
        $fntypeSubstrReplace = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64, $i64, $i32);
        $fnSubstrReplace = $this->context->module->addFunction('__compiler_substr_replace', $fntypeSubstrReplace);
        $this->context->registerFunction('__compiler_substr_replace', $fnSubstrReplace);
        $fntypePregMatch = $this->context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr
        );
        $fnPregMatch = $this->context->module->addFunction('__compiler_preg_match', $fntypePregMatch);
        $this->context->registerFunction('__compiler_preg_match', $fnPregMatch);
        $fnPregMatchAll = $this->context->module->addFunction('__compiler_preg_match_all', $fntypePregMatch);
        $this->context->registerFunction('__compiler_preg_match_all', $fnPregMatchAll);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $fntypePregMatchEx = $this->context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr,
            $valuePtr,
            $i64,
            $i64
        );
        $fnPregMatchEx = $this->context->module->addFunction('__compiler_preg_match_ex', $fntypePregMatchEx);
        $this->context->registerFunction('__compiler_preg_match_ex', $fnPregMatchEx);
        $fnPregMatchAllEx = $this->context->module->addFunction('__compiler_preg_match_all_ex', $fntypePregMatchEx);
        $this->context->registerFunction('__compiler_preg_match_all_ex', $fnPregMatchAllEx);
        $fntypePregReplace = $this->context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $strPtr,
            $i64
        );
        $fnPregReplace = $this->context->module->addFunction('__compiler_preg_replace', $fntypePregReplace);
        $this->context->registerFunction('__compiler_preg_replace', $fnPregReplace);
        $fntypePregLastError = $this->context->context->functionType($i64, false);
        $fnPregLastError = $this->context->module->addFunction('__compiler_preg_last_error', $fntypePregLastError);
        $this->context->registerFunction('__compiler_preg_last_error', $fnPregLastError);
        $fntypePregLastErrorMsg = $this->context->context->functionType($strPtr, false);
        $fnPregLastErrorMsg = $this->context->module->addFunction(
            '__compiler_preg_last_error_msg',
            $fntypePregLastErrorMsg
        );
        $this->context->registerFunction('__compiler_preg_last_error_msg', $fnPregLastErrorMsg);
        $fntypeSuperglobalName = $this->context->context->functionType($i64, false, $strPtr);
        $fnSuperglobalName = $this->context->module->addFunction(
            '__compiler_is_superglobal_name',
            $fntypeSuperglobalName
        );
        $this->context->registerFunction('__compiler_is_superglobal_name', $fnSuperglobalName);
        $fntypeGetrandom = $this->context->context->functionType($i64, false, $i8p, $sizeT, $i32);
        $fnGetrandom = $this->context->module->addFunction('getrandom', $fntypeGetrandom);
        $this->context->registerFunction('getrandom', $fnGetrandom);
        $fntypeExit = $this->context->context->functionType($void, false, $i32);
        $fnExit = $this->context->module->addFunction('exit', $fntypeExit);
        $this->context->registerFunction('exit', $fnExit);
        $fntypeAbort = $this->context->context->functionType($void, false);
        $fnAbort = $this->context->module->addFunction('abort', $fntypeAbort);
        $this->context->registerFunction('abort', $fnAbort);
        $fntypeFormatDt = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $this->context->getTypeFromString('int8')
        );
        $fnFormatDt = $this->context->module->addFunction('__compiler_format_datetime', $fntypeFormatDt);
        $this->context->registerFunction('__compiler_format_datetime', $fnFormatDt);
        $fntypeStrftime = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $this->context->getTypeFromString('int8')
        );
        $fnStrftime = $this->context->module->addFunction('__compiler_strftime', $fntypeStrftime);
        $this->context->registerFunction('__compiler_strftime', $fnStrftime);
        $fntypeStrptime = $this->context->context->functionType(
            $void,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnStrptime = $this->context->module->addFunction('__compiler_strptime', $fntypeStrptime);
        $this->context->registerFunction('__compiler_strptime', $fnStrptime);
        $fntypeDiFmt = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $f64,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fnDiFmt = $this->context->module->addFunction('__compiler_date_interval_format', $fntypeDiFmt);
        $this->context->registerFunction('__compiler_date_interval_format', $fnDiFmt);
        $fntypeIdate = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnIdate = $this->context->module->addFunction('__compiler_idate', $fntypeIdate);
        $this->context->registerFunction('__compiler_idate', $fnIdate);
        $fntypeUndefKeyStr = $this->context->context->functionType(
            $void,
            false,
            $i8p,
            $sizeT
        );
        $fnUndefKeyStr = $this->context->module->addFunction(
            '__compiler_undefined_array_key_warning_cstr',
            $fntypeUndefKeyStr
        );
        $this->context->registerFunction('__compiler_undefined_array_key_warning_cstr', $fnUndefKeyStr);
        $fntypeUndefKeyLong = $this->context->context->functionType($void, false, $i64);
        $fnUndefKeyLong = $this->context->module->addFunction(
            '__compiler_undefined_array_key_warning_long',
            $fntypeUndefKeyLong
        );
        $this->context->registerFunction('__compiler_undefined_array_key_warning_long', $fnUndefKeyLong);
        $fntypeTriggerError = $this->context->context->functionType($void, false, $i8p, $sizeT, $i32, $i8p, $i32);
        $fnTriggerError = $this->context->module->addFunction('__compiler_trigger_error', $fntypeTriggerError);
        $this->context->registerFunction('__compiler_trigger_error', $fnTriggerError);
        $fntypeAssertFail = $this->context->context->functionType($void, false, $i8p, $sizeT);
        $fnAssertFail = $this->context->module->addFunction('__compiler_assert_fail', $fntypeAssertFail);
        $this->context->registerFunction('__compiler_assert_fail', $fnAssertFail);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $fntypeAssertFailStr = $this->context->context->functionType($void, false, $strPtr);
        $fnAssertFailStr = $this->context->module->addFunction(
            '__compiler_assert_fail_string',
            $fntypeAssertFailStr
        );
        $this->context->registerFunction('__compiler_assert_fail_string', $fnAssertFailStr);
        $valPtr = $this->context->getTypeFromString('__value__*');
        $fntypeAssertOptions = $this->context->context->functionType(
            $void,
            false,
            $i32,
            $i64,
            $valPtr,
            $valPtr
        );
        $fnAssertOptions = $this->context->module->addFunction(
            '__compiler_assert_options',
            $fntypeAssertOptions
        );
        $this->context->registerFunction('__compiler_assert_options', $fnAssertOptions);
        $i8p = $this->context->getTypeFromString('int8*');
        $i64p = $this->context->getTypeFromString('int64*');
        $charPtr = $this->context->getTypeFromString('char*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $libcFns = [
            'time' => [$i64, false, [$i8p]],
            'gettimeofday' => [$i32, false, [$i8p, $i8p]],
            'getpid' => [$i32, false, []],
            'getppid' => [$i32, false, []],
            'strerror' => [$i8p, false, [$i32]],
            'getgid' => [$i32, false, []],
            'getuid' => [$i32, false, []],
            'geteuid' => [$i32, false, []],
            'getpwuid' => [$i8p, false, [$i32]],
            'localtime' => [$i8p, false, [$i64p]],
            'gmtime' => [$i8p, false, [$i64p]],
            'strftime' => [$sizeT, false, [$i8p, $sizeT, $charPtr, $i8p]],
            'strptime' => [$charPtr, false, [$charPtr, $charPtr, $i8p]],
            'timegm' => [$i64, false, [$i8p]],
            'mktime' => [$i64, false, [$i8p]],
            'sleep' => [$i32, false, [$i32]],
            'usleep' => [$i32, false, [$i32]],
            'getloadavg' => [$i32, false, [$double->pointerType(0), $i32]],
        ];
        foreach ($libcFns as $libcName => $spec) {
            [$ret, $vararg, $params] = $spec;
            $ft = $this->context->context->functionType($ret, $vararg, ...$params);
            $this->ensureExternalFunction($libcName, $ft);
        }
        $void = $this->context->getTypeFromString('void');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $i64 = $this->context->getTypeFromString('int64');
        $fntypePregSplit = $this->context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64);
        $fnPregSplit = $this->context->module->addFunction('__compiler_preg_split', $fntypePregSplit);
        $this->context->registerFunction('__compiler_preg_split', $fnPregSplit);
        $i8ppPtr = $this->context->getTypeFromString('int8**');
        $i8pppPtr = $this->context->getTypeFromString('int8***');
        $fnPendingReset = $this->context->module->addFunction(
            '__phpc_pending_header_reset',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_pending_header_reset', $fnPendingReset);
        $fnHeaderQueueEnable = $this->context->module->addFunction(
            '__phpc_header_queue_enable',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_header_queue_enable', $fnHeaderQueueEnable);
        $fnPendingAdd = $this->context->module->addFunction(
            '__phpc_pending_header_add',
            $this->context->context->functionType($void, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_pending_header_add', $fnPendingAdd);
        $fnPendingRemove = $this->context->module->addFunction(
            '__phpc_pending_header_remove',
            $this->context->context->functionType($void, false, $strPtr)
        );
        $this->context->registerFunction('__phpc_pending_header_remove', $fnPendingRemove);
        $fnPendingList = $this->context->module->addFunction(
            '__phpc_pending_header_list',
            $this->context->context->functionType($htPtr, false)
        );
        $this->context->registerFunction('__phpc_pending_header_list', $fnPendingList);
        $fnGlobVec = $this->context->module->addFunction(
            '__phpc_glob_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8pppPtr)
        );
        $this->context->registerFunction('__phpc_glob_vec', $fnGlobVec);
        $fnScandirVec = $this->context->module->addFunction(
            '__phpc_scandir_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8pppPtr)
        );
        $this->context->registerFunction('__phpc_scandir_vec', $fnScandirVec);
        $fnFileVec = $this->context->module->addFunction(
            '__phpc_file_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8pppPtr)
        );
        $this->context->registerFunction('__phpc_file_vec', $fnFileVec);
        $fnStrvecFree = $this->context->module->addFunction(
            '__phpc_strvec_free',
            $this->context->context->functionType($void, false, $i8ppPtr, $i32)
        );
        $this->context->registerFunction('__phpc_strvec_free', $fnStrvecFree);
        $fnStat = $this->context->module->addFunction(
            '__phpc_stat',
            $this->context->context->functionType($htPtr, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_stat', $fnStat);
        $fnStreamPath = $this->context->module->addFunction(
            '__phpc_stream_path',
            $this->context->context->functionType($strPtr, false, $i64)
        );
        $this->context->registerFunction('__phpc_stream_path', $fnStreamPath);
        $fnFgetcsv = $this->context->module->addFunction(
            '__compiler_fgetcsv',
            $this->context->context->functionType($htPtr, false, $i64, $i64, $strPtr, $strPtr, $strPtr)
        );
        $this->context->registerFunction('__compiler_fgetcsv', $fnFgetcsv);
        $fnStrGetcsv = $this->context->module->addFunction(
            '__compiler_str_getcsv',
            $this->context->context->functionType($htPtr, false, $strPtr, $strPtr, $strPtr, $strPtr)
        );
        $this->context->registerFunction('__compiler_str_getcsv', $fnStrGetcsv);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $i64 = $this->context->getTypeFromString('int64');
        $i1 = $this->context->getTypeFromString('int1');
        $fnParseUrl = $this->context->module->addFunction(
            '__phpc_parse_url_component',
            $this->context->context->functionType($void, false, $strPtr, $i64, $valuePtr)
        );
        $this->context->registerFunction('__phpc_parse_url_component', $fnParseUrl);
        $fnParseUrlAssoc = $this->context->module->addFunction(
            '__phpc_parse_url_assoc',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__phpc_parse_url_assoc', $fnParseUrlAssoc);
        $fnGetdate = $this->context->module->addFunction(
            '__compiler_getdate',
            $this->context->context->functionType($void, false, $i64, $valuePtr)
        );
        $this->context->registerFunction('__compiler_getdate', $fnGetdate);
        $fnLocaltime = $this->context->module->addFunction(
            '__compiler_localtime',
            $this->context->context->functionType($void, false, $i64, $i1, $valuePtr)
        );
        $this->context->registerFunction('__compiler_localtime', $fnLocaltime);
        $fnGmgetdate = $this->context->module->addFunction(
            '__compiler_gmgetdate',
            $this->context->context->functionType($void, false, $i64, $valuePtr)
        );
        $this->context->registerFunction('__compiler_gmgetdate', $fnGmgetdate);
        $fnGmmktime = $this->context->module->addFunction(
            '__compiler_gmmktime',
            $this->context->context->functionType($void, false, $i64, $i64, $i64, $i64, $i64, $i64, $i1, $valuePtr)
        );
        $this->context->registerFunction('__compiler_gmmktime', $fnGmmktime);
        $fnMktime = $this->context->module->addFunction(
            '__compiler_mktime',
            $this->context->context->functionType($void, false, $i64, $i64, $i64, $i64, $i64, $i64, $i1, $valuePtr)
        );
        $this->context->registerFunction('__compiler_mktime', $fnMktime);
        $fnGetrusage = $this->context->module->addFunction(
            '__compiler_getrusage',
            $this->context->context->functionType($void, false, $i64, $valuePtr)
        );
        $this->context->registerFunction('__compiler_getrusage', $fnGetrusage);
        $fnPendingFlush = $this->context->module->addFunction(
            '__phpc_response_headers_flush',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_response_headers_flush', $fnPendingFlush);
        $fnSetcookieAdd = $this->context->module->addFunction(
            '__phpc_setcookie_add',
            $this->context->context->functionType($void, false, $strPtr, $strPtr, $i64, $strPtr, $strPtr, $i32, $i32, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_setcookie_add', $fnSetcookieAdd);
        $fntypeSessionApply = $this->context->context->functionType($void, false, $valuePtr);
        $fnSessionStart = $this->context->module->addFunction('__phpc_session_start_apply', $fntypeSessionApply);
        $this->context->registerFunction('__phpc_session_start_apply', $fnSessionStart);
        $fnSessionWriteClose = $this->context->module->addFunction(
            '__phpc_session_write_close_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_write_close_apply', $fnSessionWriteClose);
        $fnSessionGenerateId = $this->context->module->addFunction(
            '__phpc_session_generate_new_id',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_session_generate_new_id', $fnSessionGenerateId);
        $fnSessionRegenerate = $this->context->module->addFunction(
            '__phpc_session_regenerate_id_apply',
            $this->context->context->functionType(
                $void,
                false,
                $valuePtr,
                $this->context->getTypeFromString('int8')
            )
        );
        $this->context->registerFunction('__phpc_session_regenerate_id_apply', $fnSessionRegenerate);
        $fnSessionDestroy = $this->context->module->addFunction(
            '__phpc_session_destroy_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_destroy_apply', $fnSessionDestroy);
        $fnSessionAbort = $this->context->module->addFunction(
            '__phpc_session_abort_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_abort_apply', $fnSessionAbort);
        $fnSessionReset = $this->context->module->addFunction(
            '__phpc_session_reset_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_reset_apply', $fnSessionReset);
        $fnSessionCreateId = $this->context->module->addFunction(
            '__phpc_session_create_id_apply',
            $this->context->context->functionType($void, false, $valuePtr, $strPtr)
        );
        $this->context->registerFunction('__phpc_session_create_id_apply', $fnSessionCreateId);
        $fnSessionCreateIdBoxed = $this->context->module->addFunction(
            '__phpc_session_create_id_apply_boxed',
            $this->context->context->functionType($void, false, $valuePtr, $valuePtr)
        );
        $this->context->registerFunction('__phpc_session_create_id_apply_boxed', $fnSessionCreateIdBoxed);
        $fnSessionRandomId = $this->context->module->addFunction(
            'phpc_session_random_id_string',
            $this->context->context->functionType($strPtr, false)
        );
        $this->context->registerFunction('phpc_session_random_id_string', $fnSessionRandomId);
        $fnSessionGcApply = $this->context->module->addFunction(
            '__phpc_session_gc_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_gc_apply', $fnSessionGcApply);
        $fnSessionGcExpired = $this->context->module->addFunction(
            'phpc_session_gc_expired_files',
            $this->context->context->functionType($this->context->getTypeFromString('int64'), false)
        );
        $this->context->registerFunction('phpc_session_gc_expired_files', $fnSessionGcExpired);
        $fnSessionUnset = $this->context->module->addFunction(
            '__phpc_session_unset_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_unset_apply', $fnSessionUnset);
        $fnSessionEncode = $this->context->module->addFunction(
            '__phpc_session_encode_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_encode_apply', $fnSessionEncode);
        $fnSessionDecode = $this->context->module->addFunction(
            '__phpc_session_decode_apply',
            $this->context->context->functionType($void, false, $valuePtr, $strPtr)
        );
        $this->context->registerFunction('__phpc_session_decode_apply', $fnSessionDecode);
        $fnSessionEncodeWire = $this->context->module->addFunction(
            'phpc_session_encode_wire',
            $this->context->context->functionType($strPtr, false, $this->context->getTypeFromString('__hashtable__*'))
        );
        $this->context->registerFunction('phpc_session_encode_wire', $fnSessionEncodeWire);
        $fnSessionDecodeWire = $this->context->module->addFunction(
            'phpc_session_decode_wire',
            $this->context->context->functionType(
                $this->context->getTypeFromString('__hashtable__*'),
                false,
                $strPtr
            )
        );
        $this->context->registerFunction('phpc_session_decode_wire', $fnSessionDecodeWire);
        SessionStart::registerRuntimeDeclaration($this->context);
        $fntypeJsonEncodeValue = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $valuePtr,
            $this->context->getTypeFromString('int64')
        );
        $fnJsonEncodeValue = $this->context->module->addFunction(
            '__compiler_json_encode_value',
            $fntypeJsonEncodeValue
        );
        $this->context->registerFunction('__compiler_json_encode_value', $fnJsonEncodeValue);
        $fntypeJsonEncodeArray = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*'),
            $this->context->getTypeFromString('int64')
        );
        $fnJsonEncodeArray = $this->context->module->addFunction(
            '__compiler_json_encode_array',
            $fntypeJsonEncodeArray
        );
        $this->context->registerFunction('__compiler_json_encode_array', $fnJsonEncodeArray);
        $fnJsonDecode = $this->context->module->addFunction(
            '__compiler_json_decode',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__compiler_json_decode', $fnJsonDecode);
        $fntypeJsonLastError = $this->context->context->functionType($i64, false);
        $fnJsonLastError = $this->context->module->addFunction('__compiler_json_last_error', $fntypeJsonLastError);
        $this->context->registerFunction('__compiler_json_last_error', $fnJsonLastError);
        $fntypeJsonLastErrorMsg = $this->context->context->functionType($strPtr, false);
        $fnJsonLastErrorMsg = $this->context->module->addFunction(
            '__compiler_json_last_error_msg',
            $fntypeJsonLastErrorMsg
        );
        $this->context->registerFunction('__compiler_json_last_error_msg', $fnJsonLastErrorMsg);
        $fnJsonValidate = $this->context->module->addFunction(
            '__compiler_json_validate',
            $this->context->context->functionType($i64, false, $strPtr, $i64)
        );
        $this->context->registerFunction('__compiler_json_validate', $fnJsonValidate);
        $fntypeSerializeHashtable = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnSerializeHashtable = $this->context->module->addFunction(
            '__compiler_serialize_hashtable',
            $fntypeSerializeHashtable
        );
        $this->context->registerFunction('__compiler_serialize_hashtable', $fnSerializeHashtable);
        $fntypeSerializeValue = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $valuePtr
        );
        $fnSerializeValue = $this->context->module->addFunction(
            '__compiler_serialize_value',
            $fntypeSerializeValue
        );
        $this->context->registerFunction('__compiler_serialize_value', $fnSerializeValue);
        $fnUnserialize = $this->context->module->addFunction(
            '__compiler_unserialize',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__compiler_unserialize', $fnUnserialize);
        $fntypeShellExec = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnShellExec = $this->context->module->addFunction('__compiler_shell_exec', $fntypeShellExec);
        $this->context->registerFunction('__compiler_shell_exec', $fnShellExec);
        $fnEscapeshellarg = $this->context->module->addFunction('__compiler_escapeshellarg', $fntypeShellExec);
        $this->context->registerFunction('__compiler_escapeshellarg', $fnEscapeshellarg);
        $fnEscapeshellcmd = $this->context->module->addFunction('__compiler_escapeshellcmd', $fntypeShellExec);
        $this->context->registerFunction('__compiler_escapeshellcmd', $fnEscapeshellcmd);
        $fntypePhpcRunCommand = $this->context->context->functionType(
            $this->context->getTypeFromString('__hashtable__*'),
            false,
            $strPtr,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnPhpcRunCommand = $this->context->module->addFunction('__compiler_phpc_run_command', $fntypePhpcRunCommand);
        $this->context->registerFunction('__compiler_phpc_run_command', $fnPhpcRunCommand);
        $fntypeSysGetTempDir = $this->context->context->functionType($strPtr, false);
        $fnSysGetTempDir = $this->context->module->addFunction('__compiler_sys_get_temp_dir', $fntypeSysGetTempDir);
        $this->context->registerFunction('__compiler_sys_get_temp_dir', $fnSysGetTempDir);
        $i64 = $this->context->getTypeFromString('int64');
        $fntypeGetprotobynumber = $this->context->context->functionType($strPtr, false, $i64);
        $fnGetprotobynumber = $this->context->module->addFunction(
            '__compiler_getprotobynumber',
            $fntypeGetprotobynumber
        );
        $this->context->registerFunction('__compiler_getprotobynumber', $fnGetprotobynumber);
        $fntypeGetservbyport = $this->context->context->functionType($strPtr, false, $i64, $strPtr);
        $fnGetservbyport = $this->context->module->addFunction(
            '__compiler_getservbyport',
            $fntypeGetservbyport
        );
        $this->context->registerFunction('__compiler_getservbyport', $fnGetservbyport);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $fntypeGetprotobyname = $this->context->context->functionType($void, false, $strPtr, $valuePtr);
        $fnGetprotobyname = $this->context->module->addFunction('__phpc_getprotobyname', $fntypeGetprotobyname);
        $this->context->registerFunction('__phpc_getprotobyname', $fnGetprotobyname);
        $fntypeGetservbyname = $this->context->context->functionType($void, false, $strPtr, $strPtr, $valuePtr);
        $fnGetservbyname = $this->context->module->addFunction('__phpc_getservbyname', $fntypeGetservbyname);
        $this->context->registerFunction('__phpc_getservbyname', $fnGetservbyname);
        $fntypeTempnam = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fnTempnam = $this->context->module->addFunction('__compiler_tempnam', $fntypeTempnam);
        $this->context->registerFunction('__compiler_tempnam', $fnTempnam);
        $i64 = $this->context->getTypeFromString('int64');
        $i32 = $this->context->getTypeFromString('int32');
        $fntypeFtok = $this->context->context->functionType($i64, false, $strPtr, $i32);
        $fnFtok = $this->context->module->addFunction('__compiler_ftok', $fntypeFtok);
        $this->context->registerFunction('__compiler_ftok', $fnFtok);
        $fntypeHttpBuildQuery = $this->context->context->functionType(
            $strPtr,
            false,
            $this->context->getTypeFromString('__hashtable__*'),
            $strPtr,
            $strPtr,
            $this->context->getTypeFromString('int64')
        );
        $fnHttpBuildQuery = $this->context->module->addFunction(
            '__compiler_http_build_query',
            $fntypeHttpBuildQuery
        );
        $this->context->registerFunction('__compiler_http_build_query', $fnHttpBuildQuery);
        $fntypeIconv = $this->context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $strPtr
        );
        $fnIconv = $this->context->module->addFunction('__compiler_iconv', $fntypeIconv);
        $this->context->registerFunction('__compiler_iconv', $fnIconv);
        // $this->maskedarray->register();
        // $this->nativearray->register();
    }

    public function initialize(): void {
        Sscanf::ensureLinked($this->context);
        StringFormat::ensureLinked($this->context);
        StringStripTags::ensureLinked($this->context);
        HttpResponseCode::implement($this->context);
        ObOutput::registerExternals($this->context);
        ObGzhandler::ensureLinked($this->context);
        ObOutputRuntime::ensureLinked($this->context);
        PendingHeadersRuntime::ensureLinked($this->context);
        PowIntRuntime::ensureLinked($this->context);
        GethostbynamelRuntime::ensureLinked($this->context);
        GethostbyaddrRuntime::ensureLinked($this->context);
        CheckdnsrrRuntime::ensureLinked($this->context);
        CheckdateRuntime::ensureLinked($this->context);
        DateIntervalFormatRuntime::ensureLinked($this->context);
        DefaultTimezoneRuntime::ensureLinked($this->context);
        InetRuntime::ensureLinked($this->context);
        TimeSleepRuntime::ensureLinked($this->context);
        ProcessRuntime::ensureLinked($this->context);
        ProcessOpen::ensureLinked($this->context);
        StreamSocketPair::ensureLinked($this->context);
        StreamSocketGetNameRuntime::ensureLinked($this->context);
        StringMicrotime::ensureLinked($this->context);
        StringGettimeofday::ensureLinked($this->context);
        StringGetrusage::ensureLinked($this->context);
        StringNetInterfacesJit::ensureLinked($this->context);
        StringGetenvAll::ensureLinked($this->context);
        ListUnpackRuntime::ensureLinked($this->context);
        StringInfo::ensureLinked($this->context);
        StringPhpinfoRuntime::ensureLinked($this->context);
        StringDir::ensureLinked($this->context);
        StringFsGlob::ensureLinked($this->context);
        StringFsDir::ensureLinked($this->context);
        StatCache::ensureLinked($this->context);
        StatPath::ensureLinked($this->context);
        StreamSync::ensureLinked($this->context);
        StreamCaps::ensureLinked($this->context);
        Stats::ensureLinked($this->context);
        StreamGlobals::ensureLinked($this->context);
        StreamLifecycle::ensureLinked($this->context);
        StreamIo::ensureLinked($this->context);
        GzStreamIo::ensureLinked($this->context);
        StreamBuffer::ensureLinked($this->context);
        StreamMeta::ensureLinked($this->context);
        StreamRead::ensureLinked($this->context);
        StreamResource::ensureLinked($this->context);
        LastErrorRuntime::ensureLinked($this->context);
        CliArgvRuntime::ensureLinked($this->context);
        FunctionExistsRuntime::ensureLinked($this->context);
        WeakRefRegistryRuntime::ensureLinked($this->context);
        MemoryRuntime::ensureLinked($this->context);
        IniRuntime::ensureLinked($this->context);
        IncludePathRuntime::ensureLinked($this->context);
        EnvLocalRuntime::ensureLinked($this->context);
        ErrorHandlerOutput::registerExternals($this->context);
        ExceptionHandlerOutput::registerExternals($this->context);
        StringTriggerError::ensureLinked($this->context);
        CallArgv::implement($this->context);
        ProgressNoteRuntime::ensureLinked($this->context);
        AssertFail::ensureLinked($this->context);
        AssertOptionsRuntime::ensureLinked($this->context);
        SessionLifecycleRuntime::ensureLinked($this->context);
        SessionCreateIdRuntime::ensureLinked($this->context);
        SessionGcRuntime::ensureLinked($this->context);
        SessionStart::implement($this->context);
        SessionWriteClose::implement($this->context);
        SessionRegenerateId::implement($this->context);
        SessionDestroy::implement($this->context);
        SessionAbort::implement($this->context);
        SessionUnset::implement($this->context);
        SessionStorageGlobals::ensureGlobals($this->context);
        SessionStorageRuntime::ensureLinked($this->context);
        SessionId::implement($this->context);
        SessionName::implement($this->context);
        SessionModuleName::implement($this->context);
        SessionEncodeRuntime::ensureLinked($this->context);
        DefineRuntime::ensureLinked($this->context);
        RewriteVarsRuntime::ensureLinked($this->context);
    }

    private function ensureExternalFunction(string $name, $fnType): void
    {
        if (null !== $this->context->module->getNamedFunction($name)) {
            return;
        }
        try {
            $this->context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $this->context->module->addFunction($name, $fnType);
            $this->context->registerFunction($name, $fn);
        }
    }

}
