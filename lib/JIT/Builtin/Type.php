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
        // __compiler_getenv / __compiler_getenv_all always-on shells removed (#32665):
        // StringGetenv / StringGetenvAll own the ABI (getNamedFunction first +
        // JitVmHelperLink bridge). User-script getenv()/putenv() stay
        // GetenvLookupJitHelper / PutenvJitHelper / JitEnv. Leftover Type
        // addFunction vs Runtime ABI drift mints getenv.1 (#31894 / #32122);
        // empty Type decls were also mistaken for completed bodies (#26756).
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
        // __compiler_sprintf / __compiler_printf / __compiler_number_format always-on shells
        // removed (#32921): StringFormat owns the ABI (getNamedFunction first, then
        // addFunction if absent; Type::initialize still ensureLinked). Thin AOT already
        // calls StringFormat::implementIfDeclared from JitSprintf / JitPrintf /
        // JitNumberFormat (#13571). Leftover Type empty decls vs Runtime ABI drift mint
        // sprintf.1 / number_format.1 (#31894 / #32122; float→string path #31963).
        // User-script sprintf()/printf()/number_format() stay SprintfJitHelper /
        // NumberFormatRuntime.
        // __compiler_sscanf / __compiler_sscanf_array / __compiler_sscanf_ex /
        // __compiler_vfscanf always-on shells removed (#32935): Sscanf owns the ABI
        // (StringSscanfByRef / StringSscanfArray / StringVfscanf — getNamedFunction
        // first; Type::initialize still ensureLinked). Thin AOT skips NestedJIT of
        // sscanf_array at ensureLinked (#27663); JitSscanf calls
        // StringSscanfArray::ensureLinked when the array-return path is used.
        // Leftover Type empty decls vs Runtime ABI drift mint sscanf.1 / vfscanf.1
        // (#31894 / #32122). User-script sscanf()/fscanf() stay SscanfJitHelper /
        // JitVfscanf.
        // __compiler_pack / __compiler_unpack always-on shells removed (#32936):
        // StringPack / StringUnpack own the ABI (getNamedFunction first, then
        // addFunction if absent; Type::initialize still ensureLinked). Thin AOT
        // already calls PackJitRuntime / UnpackJitRuntime from JitPack / JitUnpack.
        // Leftover Type empty decls vs Runtime ABI drift mint pack.1 / unpack.1
        // (#31894 / #32122). User-script pack()/unpack() stay PackJitHelper /
        // UnpackJitHelper.
        // __compiler_var_export / __compiler_print_r / __compiler_var_dump always-on
        // shells removed (#32941): StringVarExport / StringPrintR / StringVarDump own
        // the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Thin AOT already calls ensureLinked from JitVarExport /
        // JitPrintR / JitVarDump. Leftover Type empty decls vs Runtime ABI drift mint
        // var_export.1 / print_r.1 / var_dump.1 (#31894 / #32122). User-script
        // var_export()/print_r()/var_dump() stay VarExportJitHelper / PrintRJitHelper /
        // VarDumpJitHelper (thin scalar bridge; non-scalar needs Runtime->vm — #23540).
        // __compiler_ini_{get,cfg_get,set,restore} / __compiler_error_reporting /
        // __compiler_begin_silence / __compiler_end_silence always-on shells removed
        // (#32779): IniRuntime / SilenceRuntime own the ABI (getNamedFunction first;
        // Type::register still ensureLinked via IniRuntime). Leftover Type empty decls
        // vs Runtime ABI drift mints ini_get.1 / begin_silence.1 (#31894 / #32122).
        // User-script ini_get()/ini_set()/@ stay IniJitHelper / ErrorSilenceJitHelper.
        // __compiler_strip_tags always-on shell removed (#32971): StringStripTags owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // JitVmHelperLink::ensureBridge; Type::initialize still ensureLinked). Thin AOT
        // already calls StringStripTags::ensureLinked from ext/standard/strip_tags.php.
        // Leftover Type empty decls vs Runtime ABI drift mint strip_tags.1 (#31894 / #32122).
        // User-script strip_tags() stays StripTagsJitHelper / VmString.
        // __compiler_utf8_strlen / __compiler_utf8_valid always-on shells removed (#33001):
        // StringUtf8Runtime owns the ABI (getNamedFunction first, then addFunction if
        // absent via StringUtf8StrlenJit / StringUtf8ValidJit; Type::initialize still
        // ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // utf8_strlen.1 / utf8_valid.1 (#31894 / #32122). User-script mb_strlen() /
        // mb_check_encoding() stay JitMbStrlen / JitMbCheckEncoding / Utf8JitHelper.
        $i64 = $this->context->getTypeFromString('int64');
        $i8p = $this->context->getTypeFromString('int8*');
        $i32 = $this->context->getTypeFromString('int32');
        $sizeT = $this->context->getTypeFromString('size_t');
        // Leftover always-on libc decls removed (#32202 / peer Type $libcFns #32173):
        // getenv(3)/putenv(3) — StringGetenv::ensureLibcGetenv / BootstrapCompileSmokeM3Emit::
        //   ensureLibcPutenv after LibcExtern drops (#31637 / #31582). User-script getenv()/
        //   putenv() stay GetenvLookupJitHelper / PutenvJitHelper.
        // strlen(3) — LibcExtern::ensureStrlenDecl (#32068). User-script strlen() stays
        //   ext/types JitStrlen / VmString.
        // open/read/write/close — LibcExtern::ensurePosixFd (#31817). User-script file I/O
        //   stays on PHP helpers (`__compiler_*`).
        // fopen/fwrite/fclose — LibcExtern::ensureStdioFile (#31764). User-script fopen()
        //   stays on JitStreamIoKernel / StreamIoJitHelper.
        // lseek(2) — zero NestedJIT lookupFunction consumers.
        // __compiler_env_local_lookup / __compiler_env_register_putenv always-on shells
        // removed (#32729): EnvLocalRuntime / JitEnvLocalKernel own the ABI
        // (getNamedFunction first; Type::register still ensureLinked). Leftover Type
        // empty decls vs Runtime ABI drift mints env_local_lookup.1 (#31894 / #32122).
        // __compiler_readfile always-on shell removed (#33021): StringReadfile owns the
        // ABI (getNamedFunction first, then addFunction if absent; Type::initialize still
        // ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint readfile.1
        // (#31894 / #32122). User-script readfile() stays ReadfileJitHelper / VmFs.
        // __compiler_file_get_contents always-on shell removed (#33030): StringFileGetContents
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // file_get_contents.1 (#31894 / #32122). User-script file_get_contents() stays
        // FileGetContentsJitHelper / VmFs.
        // __compiler_include_path_init / __compiler_get_include_path /
        // __compiler_set_include_path / __compiler_restore_include_path /
        // __compiler_stream_resolve_include_path always-on shells removed
        // (#32793): IncludePathRuntime owns the ABI (getNamedFunction first;
        // Type::register still ensureLinked). Leftover Type empty decls vs
        // Runtime ABI drift mints get_include_path.1 (#31894 / #32122).
        // User-script get_include_path()/set_include_path()/
        // stream_resolve_include_path() stay IncludePathJitHelper /
        // IncludePathResolveJitHelper.
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
        $fntypeGetHeaders = $this->context->context->functionType(
            $this->context->getTypeFromString('__hashtable__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int1')
        );
        $fnGetHeaders = $this->context->module->addFunction(
            '__compiler_get_headers',
            $fntypeGetHeaders
        );
        $this->context->registerFunction('__compiler_get_headers', $fnGetHeaders);
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
        $fntypeStreamEnableCrypto = $this->context->context->functionType($i32, false, $i64, $i64, $i64, $i64);
        $fnStreamEnableCrypto = $this->context->module->addFunction('__compiler_stream_enable_crypto', $fntypeStreamEnableCrypto);
        $this->context->registerFunction('__compiler_stream_enable_crypto', $fnStreamEnableCrypto);
        // __compiler_stream_socket_get_name / __compiler_stream_socket_accept always-on
        // shells removed (#32807): StreamSocketGetNameRuntime / StreamSocketAcceptRuntime
        // own the ABI (getNamedFunction first; Type::initialize still ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mints stream_socket_get_name.1
        // (#31894 / #32122). User-script stream_socket_get_name()/stream_socket_accept()
        // stay JitStreamSocketGetName / JitStreamSocketAccept.
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
        $fntypeGetResourceType = $this->context->context->functionType($strPtr, false, $i64);
        $fnGetResourceType = $this->context->module->addFunction(
            '__compiler_get_resource_type',
            $fntypeGetResourceType
        );
        $this->context->registerFunction('__compiler_get_resource_type', $fnGetResourceType);
        // __compiler_mkdir always-on shell removed (#32438): user-script mkdir() stays
        // MkdirJitHelper / StringMkdir / VmFsDirNative. NestedJIT/AOT bridge is
        // FsDirRuntime (getNamedFunction first). Leftover Type addFunction vs
        // Runtime ABI drift mints mkdir.1 (#31894 / #32122).
        // __compiler_copy / __compiler_chown / __compiler_chgrp always-on shells
        // removed (#32466): user-script copy()/chown()/chgrp() stay CopyJitHelper /
        // ChownJitHelper / VmFs. NestedJIT/AOT bridges are CopyRuntime / ChownRuntime
        // (getNamedFunction first + JitVmHelperLink::ensureCompiled). Leftover Type
        // addFunction vs Runtime ABI drift mints copy.1 / chown.1 (#31894 / #32122).
        // __compiler_move_uploaded_file / __compiler_is_uploaded_file always-on
        // shells removed (#32499): user-script move_uploaded_file()/is_uploaded_file()
        // stay UploadTempJitHelper / VmFs. NestedJIT/AOT bridge is
        // JitUploadTempKernel (getNamedFunction first + JitVmHelperLink::ensureCompiled).
        // Leftover Type addFunction vs kernel ABI drift mints move_uploaded_file.1
        // (#31894 / #32122).
        // __compiler_touch always-on shell removed (#32510): user-script touch() stays
        // JitTouch / VmFs. NestedJIT/AOT bridge is FsDirRuntime
        // (getNamedFunction first; body TouchLibcRuntime utime). Leftover Type
        // addFunction vs Runtime ABI drift mints touch.1 (#31894 / #32122).
        // __compiler_opendir / __compiler_readdir / __compiler_closedir /
        // __compiler_rewinddir always-on shells removed (#32548): user-script
        // opendir()/readdir()/closedir()/rewinddir() stay StringOpendir /
        // StringDir / ext/standard Jit*. NestedJIT/AOT bridge is StringDirRuntime
        // (getNamedFunction first; body DirHandleJitHelper). Leftover Type
        // addFunction vs Runtime ABI drift mints opendir.1 (#31894 / #32122).
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
        // __compiler_hash / __compiler_hash_hmac / __compiler_hash_pbkdf2 /
        // __compiler_hash_hkdf always-on shells removed (#32876): NestedJIT/AOT
        // bridge is StringHashCrypto → StringHashCryptoPhp (getNamedFunction first via
        // JitVmHelperLink::ensureBridge; Type::initialize still ensureLinked). Leftover
        // Type empty decls vs Runtime ABI drift mint hash.1 (#31894 / #32122).
        // __compiler_hash_equals / __compiler_hash_hmac_algos / __compiler_hash_algos
        // always-on shells removed (#32875): NestedJIT/AOT bridges are StringHashEquals /
        // StringHashHmacAlgos / StringHashAlgos (JitVmHelperLink::ensureBridge;
        // Type::initialize still ensureLinked). Leftover Type empty decls vs Runtime
        // ABI drift mint hash_equals.1 (#31894 / #32122).
        // __compiler_openssl_sign / __compiler_openssl_verify always-on shells removed
        // (#32866): NestedJIT/AOT bridge is OpensslSignRuntime (getNamedFunction first;
        // Type::initialize still ensureLinked). Leftover Type empty decls vs Runtime
        // ABI drift mint openssl_sign.1 (#31894 / #32122).
        // __compiler_openssl_encrypt / __compiler_openssl_decrypt /
        // __compiler_openssl_encrypt_take_tag / __compiler_openssl_encrypt_tag_is_null
        // always-on shells removed (#32859): NestedJIT/AOT bridge is OpensslEncryptRuntime
        // (getNamedFunction first; Type::initialize still ensureLinked). Leftover Type
        // empty decls vs Runtime ABI drift mint openssl_encrypt.1 (#31894 / #32122).
        // __compiler_openssl_digest always-on shell removed (#32868): NestedJIT/AOT
        // bridge is OpensslDigestRuntime (getNamedFunction first; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // openssl_digest.1 (#31894 / #32122).
        // __compiler_openssl_pbkdf2 always-on shell removed (#32869): NestedJIT/AOT
        // bridge is OpensslPbkdf2Runtime (HMAC over __compiler_hash + LLVM hex-decode;
        // getNamedFunction first; Type::initialize still ensureLinked). Leftover Type
        // empty decls vs Runtime ABI drift mint openssl_pbkdf2.1 (#31894 / #32122).
        // __compiler_openssl_get_cipher_methods / __compiler_openssl_get_md_methods always-on
        // shells removed (#32451): user-script openssl_get_*_methods() stays
        // JitOpensslMethods / OpensslMethodsJitHelper / OpensslCipherRegistry.
        // NestedJIT/AOT bridge is OpensslMethodsRuntime (getNamedFunction first +
        // JitVmHelperLink::ensureBridge). Leftover Type addFunction vs Runtime ABI
        // drift mints openssl_get_cipher_methods.1 (#31894 / #32122).
        // __compiler_microtime_string / __compiler_microtime_float always-on shells
        // removed (#32683): user-script microtime() stays JitDate / MicrotimeJitHelper.
        // NestedJIT/AOT bridge is StringMicrotime (getNamedFunction first +
        // JitVmHelperLink::ensureBridge). Leftover Type addFunction vs Runtime ABI
        // drift mints microtime_float.1 (#31894 / #32122).
        // __compiler_phpversion / __compiler_php_sapi_name / __compiler_zend_version /
        // __compiler_php_uname / __compiler_extension_loaded /
        // __compiler_get_loaded_extensions / __compiler_get_extension_funcs always-on
        // shells removed (#32839): user-script phpversion()/php_sapi_name()/zend_version()/
        // php_uname()/extension_loaded()/get_loaded_extensions()/get_extension_funcs()
        // stay JitInfo / InfoJitHelper (php-src ext/standard/info.c). NestedJIT/AOT
        // bridge is StringInfo (getNamedFunction first; Type::initialize still
        // ensureLinked). Leftover Type empty decls vs Runtime ABI drift mints
        // phpversion.1 (#31894 / #32122).
        // __compiler_version_compare always-on shell removed (#32843): user-script
        // version_compare() stays JitInfo / VersionCompareJitHelper (php-src
        // ext/standard/versioning.c). NestedJIT/AOT bridge is StringVersionCompare
        // (getNamedFunction first; Type::initialize still ensureLinked). Leftover
        // Type empty decls vs Runtime ABI drift mints version_compare.1 (#31894 / #32122).
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        // __compiler_gettimeofday_array / __compiler_gettimeofday_float always-on
        // shells removed (#32683): user-script gettimeofday() stays JitGettimeofday /
        // GettimeofdayJitHelper (php-src ext/standard/microtime.c). NestedJIT/AOT
        // bridge is StringGettimeofday (getNamedFunction first + JitVmHelperLink).
        // Leftover Type addFunction vs Runtime ABI drift mints gettimeofday_array.1
        // (#31894 / #32122).
        // __compiler_hrtime_ns / __compiler_hrtime_pair always-on shells removed
        // (#32712): user-script hrtime() stays JitDate / HrtimeJitHelper /
        // VmHrtimeNative (php-src ext/standard/hrtime.c). NestedJIT/AOT bridge is
        // StringHrtimeRuntime (getNamedFunction first + JitVmHelperLink). Leftover
        // Type addFunction vs Runtime ABI drift mints hrtime_ns.1 (#31894 / #32122).
        // Ns return ABI stays on the owner: i64 vs double per
        // CompilerVersion::supportsHrtimeAsNumberFloat() (#26910).
        // __compiler_time_nanosleep / __compiler_time_sleep_until always-on shells
        // removed (#32721): user-script time_nanosleep()/time_sleep_until() stay
        // JitSleep / SleepJitHelper / VmSleepPure (php-src ext/standard/basic_functions.c).
        // NestedJIT/AOT bridge is TimeSleepRuntime (getNamedFunction first +
        // JitVmHelperLink::ensureBridge). Leftover Type addFunction vs Runtime ABI
        // drift mints time_nanosleep.1 (#31894 / #32122).
        // __compiler_password_random_bytes / __compiler_libcrypt always-on shells
        // removed (#32851): NestedJIT/AOT bridges are PasswordRandomBytesRuntime /
        // LibcryptRuntime (getNamedFunction first; Type::initialize still ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint password_random_bytes.1 /
        // libcrypt.1 (#31894 / #32122).
        // __compiler_password_hash / __compiler_password_verify / __compiler_crypt /
        // __compiler_password_get_info / __compiler_password_needs_rehash /
        // __compiler_password_algos always-on shells removed (#32855): NestedJIT/AOT
        // bridge is PasswordCryptoRuntime (getNamedFunction first; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // password_hash.1 (#31894 / #32122).
        // __compiler_strtr / __compiler_strtr_array always-on shells removed (#32858):
        // NestedJIT/AOT bridge is StringStrtr (getNamedFunction first; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // strtr.1 (#31894 / #32122).
        // __compiler_convert_uuencode / __compiler_convert_uudecode always-on shells
        // removed (#32982): NestedJIT/AOT bridge is StringConvertUu (getNamedFunction
        // first via JitVmHelperLink::ensureBridge; Type::initialize still ensureLinked).
        // Thin AOT already calls StringConvertUu::ensureLinked from
        // ext/standard/JitConvertUuencode.php / JitConvertUudecode.php. Leftover Type
        // empty decls vs Runtime ABI drift mint convert_uuencode.1 / convert_uudecode.1
        // (#31894 / #32122). User-script convert_uuencode()/convert_uudecode() stays
        // ConvertUuJitHelper / VmConvertUu / VmString.
        // __compiler_quoted_printable_encode / __compiler_quoted_printable_decode
        // always-on shells removed (#32882): NestedJIT/AOT bridge is StringQuotPrint
        // (JitVmHelperLink::ensureBridge; Type::initialize still ensureLinked). Leftover
        // Type empty decls vs Runtime ABI drift mint quoted_printable_encode.1
        // (#31894 / #32122).
        // __compiler_utf8_encode / __compiler_utf8_decode always-on shells removed
        // (#32879): NestedJIT/AOT bridge is StringUtf8Latin1 (getNamedFunction first;
        // Type::initialize still ensureLinked). Leftover Type empty decls vs Runtime
        // ABI drift mint utf8_encode.1 (#31894 / #32122).
        // __compiler_addcslashes / __compiler_stripcslashes always-on shells removed
        // (#32893): NestedJIT/AOT bridge is StringCslashes (JitVmHelperLink::ensureBridge;
        // Type::initialize still ensureLinked / ensureStripcslashes). Leftover Type empty
        // decls vs Runtime ABI drift mint addcslashes.1 (#31894 / #32122).
        // __compiler_substr_replace always-on shell removed (#32250): user-script
        // substr_replace() stays VmString / ext/standard/substr_replace.php. No
        // NestedJIT lookupFunction remains.
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
        // getrandom(3) always-on decl removed (#32139): user-script random_bytes()
        // remains PHP helpers (`RandomBytesJitHelper` / `StringRandomBytes` /
        // `__compiler_random_bytes`) and NestedJIT uses /dev/urandom via
        // JitRandomBytesKernel open/read (#29531 / #31817). No NestedJIT getrandom
        // lookups remain. Peer sprintf drop (#32110).
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
        $f64 = $this->context->getTypeFromString('double');
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
        // __compiler_idate always-on shell removed (#32250): user-script idate()
        // stays JitIdate IR / IdateJitHelper (#26900). StringIdate::implement()
        // is an intentional no-op.
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
        // Leftover always-on $libcFns removed (#32217 / peer Type I/O #32202 / calendar #32173):
        // time(2) — JitTimeKernel::ensureLibcTime (#30332). User-script time() stays TimeJitHelper.
        // gettimeofday(2) — JitMicrotimeKernel::ensureLibcGettimeofday (#29405).
        // getpid(2) — JitGetmypidKernel::ensureLibcGetpid (#30623).
        // getppid/getgid/getuid/geteuid — JitPosixGet*Kernel::ensureLibc* (#30728–#30803).
        // strerror(3) — SocketErrorRuntime::ensureStrerrorLibc / JitFtok::ensureWarningLibc.
        // getpwuid(3)+geteuid(2) — JitGetCurrentUser::ensureLibcGeteuid/Getpwuid (#32217).
        // Dead calendar/sleep/getloadavg already dropped (#32173). Keep exit/abort.
        $void = $this->context->getTypeFromString('void');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $i64 = $this->context->getTypeFromString('int64');
        $fntypePregSplit = $this->context->context->functionType($htPtr, false, $strPtr, $strPtr, $i64, $i64);
        $fnPregSplit = $this->context->module->addFunction('__compiler_preg_split', $fntypePregSplit);
        $this->context->registerFunction('__compiler_preg_split', $fnPregSplit);
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
        // __phpc_glob_vec / __phpc_scandir_vec / __phpc_strvec_free always-on shells
        // removed (#32636): JitFsGlobKernel declares module-locally (getNamedFunction first);
        // user-script glob()/scandir() stay FsGlobJitHelper / VmFsGlob (#27235/#27236).
        // __phpc_file_vec always-on shell removed (#32250): zero NestedJIT consumers.
        // __phpc_stat always-on shell removed (#32651): StatArrayRuntime declares
        // module-locally (getNamedFunction first); user-script stat()/lstat() stay
        // StatArrayJitHelper / VmFs::statInfo (php-src ext/standard/filestat.c).
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
        // __compiler_getdate always-on shell removed (#32250): user-script getdate()
        // stays JitGetdate IR / GetdateJitHelper (#26900). StringGetdate::implement()
        // is an intentional no-op.
        // __compiler_localtime / __compiler_gmgetdate / __compiler_gmmktime always-on
        // shells removed (#32636): StringLocaltime / StringGmgetdate / StringGmmktime
        // own the ABI (getNamedFunction first); php-src ext/standard/datetime.c.
        // __compiler_mktime / __compiler_getrusage always-on shells removed (#32651):
        // StringMktime / StringGetrusageRuntime own the ABI (getNamedFunction first);
        // user-script mktime()/getrusage() stay MktimeJitHelper / GetrusageJitHelper
        // (php-src ext/date/php_date.c / ext/standard/basic_functions.c).
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
        SessionStartOptionsRuntime::ensureLinked($this->context);
        // __compiler_json_encode_value / __compiler_json_encode_array /
        // __compiler_json_quote_string / __compiler_json_decode /
        // __compiler_json_last_error / __compiler_json_last_error_msg /
        // __compiler_json_set_last_error / __compiler_json_validate always-on shells
        // removed (#32897): user-script json_encode()/json_decode()/json_last_error*()
        // /json_validate() stay StringJsonEncode / StringJsonDecode /
        // JsonEncodeQuoteStringRuntime. NestedJIT/AOT bridges getNamedFunction first
        // (JitVmHelperLink::ensureBridge / Runtime implement); Type::initialize still
        // ensureLinked. Leftover Type empty decls vs Runtime ABI drift mint
        // json_encode.1 (#31894 / #32122).
        // __compiler_xmlrpc_encode_value / __compiler_xmlrpc_decode always-on shells
        // removed (#32902): __compiler_xmlrpc_encode_array already dropped (#32250).
        // User-script xmlrpc_encode()/xmlrpc_decode() stay StringXmlrpc /
        // ext/xmlrpc/JitXmlrpc. NestedJIT/AOT bridges getNamedFunction first
        // (JitVmHelperLink::ensureBridge / decode emit addFunction if absent);
        // Type::initialize still ensureLinked. Leftover Type empty decls vs Runtime
        // ABI drift mint xmlrpc_encode.1 (#31894 / #32122).
        $fntypeSerializeHashtable = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*'),
            $this->context->getTypeFromString('int64')
        );
        $fnSerializeHashtable = $this->context->module->addFunction(
            '__compiler_serialize_hashtable',
            $fntypeSerializeHashtable
        );
        $this->context->registerFunction('__compiler_serialize_hashtable', $fnSerializeHashtable);
        $fntypeSerializeValue = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $valuePtr,
            $this->context->getTypeFromString('int64')
        );
        $fnSerializeValue = $this->context->module->addFunction(
            '__compiler_serialize_value',
            $fntypeSerializeValue
        );
        $this->context->registerFunction('__compiler_serialize_value', $fnSerializeValue);
        $fntypeSerializeObject = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $strPtr,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnSerializeObject = $this->context->module->addFunction(
            '__compiler_serialize_object',
            $fntypeSerializeObject
        );
        $this->context->registerFunction('__compiler_serialize_object', $fnSerializeObject);
        // Returns __value__* (ArrayPop #12647 / #20785) — not void+out-pointer.
        $fnUnserialize = $this->context->module->addFunction(
            '__compiler_unserialize',
            $this->context->context->functionType($valuePtr, false, $strPtr)
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
        // __compiler_sys_get_temp_dir always-on shell removed (#32438): user-script
        // sys_get_temp_dir() stays SysGetTempDirJitHelper / SysGetTempDirRuntime
        // (NestedJIT leaf is __compiler_sys_get_temp_dir_leaf). Peer mkdir drop.
        // __compiler_getprotobynumber / __compiler_getservbyport / __phpc_getprotobyname /
        // __phpc_getservbyname always-on shells removed (#32701): user-script
        // getprotobynumber()/getservbyport()/getprotobyname()/getservbyname() stay
        // NetworkServicesJitHelper / NetworkServicesNameLookupJitHelper / JitNetworkServices.
        // NestedJIT/AOT bridges are StringNetworkServicesStringReturn /
        // StringNetworkServicesNameLookup (getNamedFunction first). Leftover Type
        // addFunction vs Runtime ABI drift mints name.1 (#31894 / #32122).
        // __compiler_tempnam / __compiler_ftok always-on shells removed (#32438):
        // user-script tempnam() stays TempnamJitHelper; ftok() stays FtokJitHelper
        // / FtokRuntime::ensureLinked / NestedJIT JitFtokKernel. FsDirRuntime owns
        // the tempnam AOT bridge (getNamedFunction first). Peer mkdir drop.
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
        // __compiler_iconv always-on shell removed (#32482): user-script iconv() stays
        // IconvJitHelper / VmIconv. NestedJIT/AOT bridge is IconvRuntime
        // (getNamedFunction first + JitVmHelperLink::ensureCompiled). Leftover Type
        // addFunction vs Runtime ABI drift mints iconv.1 (#31894 / #32122).
        // $this->maskedarray->register();
        // $this->nativearray->register();
    }

    public function initialize(): void {
        // Eager NestedJIT ensureLinked on EMBED during Type::initialize is fragile
        // (#20930, #21109): after NativeOps getValue() fix, Sscanf/ObGzhandler abort
        // or segfault mid-init. Link on first use (peer PendingHeaders / STANDALONE #12910).
        HttpResponseCode::implement($this->context);
        ObOutput::registerExternals($this->context);
        // Thin user-script AOT: lazy-link PendingHeaders on first header()/headers_list use
        // — NestedJIT during Type::initialize segfaults (#20930, peer #13571).
        if (!$this->context->isThinStandaloneAotMain()) {
            PendingHeadersRuntime::ensureLinked($this->context);
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $this->loadType
            || Builtin::LOAD_TYPE_EMBED === $this->loadType
        ) {
            // Remaining runtimes link lazily via ensureLinked / ensureStandaloneBodies
            // (#12910, #21109).
            return;
        }
        PowIntRuntime::ensureLinked($this->context);
        GethostbynamelRuntime::ensureLinked($this->context);
        GethostbyaddrRuntime::ensureLinked($this->context);
        CheckdnsrrRuntime::ensureLinked($this->context);
        CheckdateRuntime::ensureLinked($this->context);
        DateIntervalFormatRuntime::ensureLinked($this->context);
        DefaultTimezoneRuntime::ensureLinked($this->context);
        DefaultTimezoneCivilRuntime::ensureLinked($this->context);
        InetRuntime::ensureLinked($this->context);
        TimeSleepRuntime::ensureLinked($this->context);
        ProcessRuntime::ensureLinked($this->context);
        ProcessOpen::ensureLinked($this->context);
        StreamSocketPair::ensureLinked($this->context);
        StreamSocketGetNameRuntime::ensureLinked($this->context);
        StreamSocketAccept::ensureLinked($this->context);
        StringMicrotime::ensureLinked($this->context);
        StringTime::ensureLinked($this->context);
        ProcessIdentityJit::ensureLinked($this->context);
        FtokRuntime::ensureLinked($this->context);
        PosixGetpidJit::ensureLinked($this->context);
        PosixGetppidJit::ensureLinked($this->context);
        PosixGetuidJit::ensureLinked($this->context);
        PosixGeteuidJit::ensureLinked($this->context);
        PosixGetgidJit::ensureLinked($this->context);
        PosixGetegidJit::ensureLinked($this->context);
        PosixSetuidJit::ensureLinked($this->context);
        PosixSetgidJit::ensureLinked($this->context);
        PosixSeteuidJit::ensureLinked($this->context);
        PosixSetegidJit::ensureLinked($this->context);
        PosixSetsidJit::ensureLinked($this->context);
        PosixSetpgidJit::ensureLinked($this->context);
        StringGettimeofday::ensureLinked($this->context);
        StringGetrusage::ensureLinked($this->context);
        StringNetInterfacesJit::ensureLinked($this->context);
        StringGetenv::ensureLinked($this->context);
        StringGetenvAll::ensureLinked($this->context);
        ListUnpackRuntime::ensureLinked($this->context);
        StringInfo::ensureLinked($this->context);
        StringVersionCompare::ensureLinked($this->context);
        LibcryptRuntime::ensureLinked($this->context);
        PasswordRandomBytesRuntime::ensureLinked($this->context);
        PasswordCryptoRuntime::ensureLinked($this->context);
        StringHashCrypto::ensureLinked($this->context);
        OpensslEncryptRuntime::ensureLinked($this->context);
        OpensslSignRuntime::ensureLinked($this->context);
        OpensslDigestRuntime::ensureLinked($this->context);
        OpensslPbkdf2Runtime::ensureLinked($this->context);
        StringHashEquals::ensureLinked($this->context);
        StringHashHmacAlgos::ensureLinked($this->context);
        StringHashAlgos::ensureLinked($this->context);
        StringJsonEncode::ensureLinked($this->context);
        StringJsonDecode::ensureLinked($this->context);
        StringXmlrpc::ensureLinked($this->context);
        StringFormat::ensureLinked($this->context);
        Sscanf::ensureLinked($this->context);
        StringPack::ensureLinked($this->context);
        StringUnpack::ensureLinked($this->context);
        StringVarExport::ensureLinked($this->context);
        StringPrintR::ensureLinked($this->context);
        StringVarDump::ensureLinked($this->context);
        StringStripTags::ensureLinked($this->context);
        StringConvertUu::ensureLinked($this->context);
        StringQuotPrint::ensureLinked($this->context);
        StringUtf8Latin1::ensureLinked($this->context);
        StringUtf8Runtime::ensureLinked($this->context);
        StringReadfile::ensureLinked($this->context);
        StringFileGetContents::ensureLinked($this->context);
        StringCslashes::ensureStandaloneBodies($this->context);
        StringStrtr::ensureLinked($this->context);
        StringPhpinfoRuntime::ensureLinked($this->context);
        StringDir::ensureLinked($this->context);
        DirectoryIteratorSnapshotRuntime::ensureLinked($this->context);
        GlobIteratorSnapshotRuntime::ensureLinked($this->context);
        SplFileObjectSnapshotRuntime::ensureLinked($this->context);
        StringFsGlob::ensureLinked($this->context);
        StringFsDir::ensureLinked($this->context);
        StatCache::ensureLinked($this->context);
        StatPath::ensureLinked($this->context);
        StreamSync::ensureLinked($this->context);
        StreamIo::ensureLinked($this->context);
        StreamCaps::ensureLinked($this->context);
        Stats::ensureLinked($this->context);
        StreamGlobals::ensureLinked($this->context);
        StreamLifecycle::ensureLinked($this->context);
        GzStreamIo::ensureLinked($this->context);
        Bz2StreamIo::ensureLinked($this->context);
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

}
