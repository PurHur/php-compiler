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
        // __compiler_phpc_deploy_path always-on shell removed (#33225): StringDeployPath
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still StringDeployPath::ensureLinked on the full load path; JitDeployPath
        // ensureLinked before lookup). Leftover Type empty decls vs Runtime ABI drift
        // mint phpc_deploy_path.1 (#31894 / #32122). User-script phpc_deploy_path() stays
        // JitDeployPath / DeployPathLlvm thin AOT (#33244); VM SSOT DeployPathJitHelper.
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
        // __compiler_mime_content_type always-on shell removed (#33034): MimeContentTypeRuntime
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // mime_content_type.1 (#31894 / #32122). User-script mime_content_type() stays
        // MimeContentTypeJitHelper / VmMime.
        // __compiler_include_path_init / __compiler_get_include_path /
        // __compiler_set_include_path / __compiler_restore_include_path /
        // __compiler_stream_resolve_include_path always-on shells removed
        // (#32793): IncludePathRuntime owns the ABI (getNamedFunction first;
        // Type::register still ensureLinked). Leftover Type empty decls vs
        // Runtime ABI drift mints get_include_path.1 (#31894 / #32122).
        // User-script get_include_path()/set_include_path()/
        // stream_resolve_include_path() stay IncludePathJitHelper /
        // IncludePathResolveJitHelper.
        // __compiler_get_meta_tags always-on shell removed (#33035): MetaTagsRuntime
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // get_meta_tags.1 (#31894 / #32122). User-script get_meta_tags() stays
        // MetaTagsJitHelper / VmMetaTags.
        // __compiler_error_log always-on shell removed (#33044): StringErrorLog
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // error_log.1 (#31894 / #32122). User-script error_log() stays
        // ErrorLogJitHelper / VmErrorLog.
        // __compiler_get_headers always-on shell removed (#33042): GetHeadersRuntime
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // get_headers.1 (#31894 / #32122). User-script get_headers() stays
        // GetHeadersJitHelper / VmHttpHeaders (#27317).
        // __compiler_file_put_contents always-on shell removed (#33043): StringFilePutContents
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // file_put_contents.1 (#31894 / #32122). User-script file_put_contents() stays
        // FilePutContentsJitHelper / VmFs::filePutContents.
        // __compiler_fwrite always-on shell removed (#33048): StreamIoRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via
        // ensureRuntimeAbiDeclared / implementFwriteBridge; Type::initialize still
        // StreamIo::ensureLinked → StreamIoJit → StreamIoRuntime). Leftover Type empty
        // decls vs Runtime ABI drift mint fwrite.1 (#31894 / #32122). User-script
        // fwrite() stays JitFwrite / StreamIoJitHelper / JitStreamIoKernel.
        $strPtr = $this->context->getTypeFromString('__string__*');
        // __compiler_fopen always-on shell removed (#33049): StreamIoRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via declareRuntimeFn;
        // Type::initialize still StreamIo::ensureLinked). Leftover Type empty decls vs
        // Runtime ABI drift mint fopen.1 (#31894 / #32122). User-script fopen() stays
        // JitStreamIoKernel / StreamIoJitHelper / VmFs.
        // __compiler_fread always-on shell removed (#33055): StreamIoRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via declareRuntimeFn
        // + implementNullableStringBridge; Type::initialize still StreamIo::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint fread.1 (#31894 / #32122).
        // User-script fread() stays StreamIoJitHelper / JitStreamIoKernel.
        // __compiler_tmpfile always-on shell removed (#33067): StreamIoRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via declareRuntimeFn
        // + implementNullaryI64Bridge; Type::initialize still StreamIo::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint tmpfile.1 (#31894 / #32122).
        // User-script tmpfile() stays StreamIoJitHelper / JitStreamIoKernel.
        // __compiler_fclose always-on shell removed (#33073): JitStreamLifecycleKernel owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementCloseBridge; Type::initialize still StreamLifecycle::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint fclose.1 (#31894 / #32122).
        // User-script fclose() stays JitFclose / StreamLifecycleJitHelper.
        // __compiler_fflush always-on shell removed (#33084): JitStreamLifecycleKernel owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementIfMissing; Type::initialize still StreamLifecycle::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint fflush.1 (#31894 / #32122).
        // User-script fflush() stays JitFflush / StreamLifecycleJitHelper.
        // __compiler_is_resource always-on shell removed (#33088): JitStreamLifecycleKernel /
        // StreamGlobalsJit::implementThinIsResource owns the ABI (getNamedFunction first,
        // then addFunction if absent; Type::initialize still StreamLifecycle::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint is_resource.1 (#31894 / #32122).
        // User-script is_resource() stays is_resource_ / StreamLifecycleJitHelper.
        // __compiler_pclose always-on shell removed (#33093): JitStreamLifecycleKernel owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementCloseBridge; Type::initialize still StreamLifecycle::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint pclose.1 (#31894 / #32122).
        // User-script pclose() stays JitPclose / StreamLifecycleJitHelper.
        // __compiler_popen always-on shell removed (#33100): StreamIoRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via declareRuntimeFn
        // + implementBinaryStringBridge; Type::initialize still StreamIo::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint popen.1 (#31894 / #32122).
        // User-script popen() stays StreamIoJitHelper / JitStreamIoKernel.
        // __compiler_proc_open always-on shell removed (#33105): ProcessOpenEmbedBridge owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementProcOpenBridge; Type::initialize still ProcessOpen::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint proc_open.1 (#31894 / #32122).
        // User-script proc_open() stays JitProcOpen / ProcessOpenJitHelper.
        // __compiler_proc_close always-on shell removed (#33118): ProcessOpenEmbedBridge owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementI32Bridge; Type::initialize still ProcessOpen::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint proc_close.1 (#31894 / #32122).
        // User-script proc_close() stays JitProcClose / ProcessOpenJitHelper.
        // __compiler_is_process_resource always-on shell removed (#33121): ProcessOpenEmbedBridge
        // owns the ABI (getNamedFunction first, then addFunction if absent via
        // implementI32Bridge; Type::initialize still ProcessOpen::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint is_process_resource.1
        // (#31894 / #32122). User-script process-handle probes stay ProcessOpenJitHelper /
        // JitStreamResourceKernel stub when ProcessOpen is not linked.
        // __compiler_get_resources always-on shell removed (#33130): StreamResource /
        // JitStreamResourceKernel owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementIfMissing; Type::initialize still StreamResource::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint get_resources.1
        // (#31894 / #32122). User-script get_resources() stays JitGetResources /
        // StreamResourceJitHelper.
        // __compiler_flock always-on shell removed (#33104): StreamReadRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via
        // JitStreamReadBridgeKernel::implementI32Bridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift
        // mint flock.1 (#31894 / #32122). User-script flock() stays JitFlock /
        // StreamReadJitHelper.
        // __compiler_fpassthru always-on shell removed (#33106): StreamReadRuntime owns the
        // ABI (getNamedFunction first, then addFunction if absent via implementI64Bridge;
        // Type::initialize still StreamRead::ensureLinked). Leftover Type empty decls vs
        // Runtime ABI drift mint fpassthru.1 (#31894 / #32122). User-script fpassthru()
        // stays StreamReadJitHelper / JitStreamReadBridgeKernel.
        // __compiler_feof always-on shell removed (#33080): JitStreamLifecycleKernel owns
        // the ABI (getNamedFunction first, then addFunction if absent via
        // implementIfMissing; Type::initialize still StreamLifecycle::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint feof.1 (#31894 / #32122).
        // User-script feof() stays JitFeof / StreamLifecycleJitHelper.
        // __compiler_fsync always-on shell removed (#33114): StreamSync / JitStreamSyncKernel
        // owns the ABI (getNamedFunction first, then addFunction if absent via
        // implementIfMissing; Type::initialize still StreamSync::ensureLinked). Leftover
        // Type empty decls vs Runtime ABI drift mint fsync.1 (#31894 / #32122). User-script
        // fsync() stays StreamSync / JitStreamSyncKernel (libc after stream resolve).
        // __compiler_fdatasync always-on shell removed (#33123): StreamSync / JitStreamSyncKernel
        // owns the ABI (getNamedFunction first, then addFunction if absent via
        // implementIfMissing; Type::initialize still StreamSync::ensureLinked). Leftover
        // Type empty decls vs Runtime ABI drift mint fdatasync.1 (#31894 / #32122). User-script
        // fdatasync() stays StreamSync / JitStreamSyncKernel (libc after stream resolve).
        // __compiler_stream_set_chunk_size always-on shell removed (#33127): StreamBuffer /
        // JitStreamBufferKernel owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementIfMissing; Type::initialize still StreamBuffer::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint stream_set_chunk_size.1
        // (#31894 / #32122). User-script stream_set_chunk_size() stays JitStreamSetChunkSize /
        // StreamBufferJitHelper.
        // __compiler_stream_set_timeout always-on shell removed (#33134): StreamBuffer /
        // JitStreamBufferKernel owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementIfMissing; Type::initialize still StreamBuffer::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint stream_set_timeout.1
        // (#31894 / #32122). User-script stream_set_timeout() stays JitStreamSetTimeout /
        // StreamBufferJitHelper.
        // __compiler_stream_set_write_buffer always-on shell removed (#33139): StreamBuffer /
        // JitStreamBufferKernel owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementIfMissing; Type::initialize still StreamBuffer::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint stream_set_write_buffer.1
        // (#31894 / #32122). User-script stream_set_write_buffer() stays JitStreamSetWriteBuffer /
        // StreamBufferJitHelper.
        // __compiler_stream_set_read_buffer always-on shell removed (#33142): StreamBuffer /
        // JitStreamBufferKernel owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementIfMissing; Type::initialize still StreamBuffer::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint stream_set_read_buffer.1
        // (#31894 / #32122). User-script stream_set_read_buffer() stays JitStreamSetReadBuffer /
        // StreamBufferJitHelper.
        // __compiler_stream_supports always-on shell removed (#33145): StreamIo /
        // StreamIoRuntime / JitStreamIoKernel owns the ABI (getNamedFunction first, then
        // addFunction if absent via implementIfMissing/declareRuntimeFn; Type::initialize
        // still StreamIo::ensureLinked). Leftover Type empty decls vs Runtime ABI drift
        // mint stream_supports.1 (#31894 / #32122). User-script stream_supports() /
        // stream_supports_lock() stays JitStreamSupports / StreamIoJitHelper.
        // __compiler_stream_is_local always-on shell removed (#33148): StreamCaps /
        // StreamCapsRuntime / JitStreamCapsKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementSingleArgBridge; Type::initialize still
        // StreamCaps::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_is_local.1 (#31894 / #32122). User-script stream_is_local() stays
        // JitStreamIsLocal / StreamCapsJitHelper.
        // __compiler_stream_is_local_uri always-on shell removed (#33150): StreamCaps /
        // StreamCapsRuntime / JitStreamCapsKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementIfMissing/implementIsLocalUriBridge;
        // Type::initialize still StreamCaps::ensureLinked). Leftover Type empty decls vs
        // Runtime ABI drift mint stream_is_local_uri.1 (#31894 / #32122). User-script
        // stream_is_local() path/uri helper stays JitStreamIsLocal / StreamCapsJitHelper.
        // __compiler_stream_isatty always-on shell removed (#33151): StreamCaps /
        // StreamCapsRuntime / JitStreamCapsKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementSingleArgBridge; Type::initialize still
        // StreamCaps::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_isatty.1 (#31894 / #32122). User-script stream_isatty() stays
        // JitStreamIsatty / StreamCapsJitHelper.
        // __compiler_stream_get_meta_data always-on shell removed (#33154): StreamMeta /
        // JitStreamMetaKernel / JitStreamMetaThinAot owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementIfMissing; Type::initialize still
        // StreamMeta::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_get_meta_data.1 (#31894 / #32122). User-script stream_get_meta_data()
        // stays JitStreamGetMetaData / StreamMetaJitHelper.
        // __compiler_stream_set_blocking always-on shell removed (#33157): StreamMeta /
        // JitStreamMetaKernel / JitStreamMetaThinAot owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementIfMissing; Type::initialize still
        // StreamMeta::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_set_blocking.1 (#31894 / #32122). User-script stream_set_blocking() stays
        // JitStreamSetBlocking / StreamMetaJitHelper.
        // __compiler_stream_enable_crypto always-on shell removed (#33159): StreamMeta /
        // JitStreamMetaKernel / JitStreamMetaThinAot owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementIfMissing; Type::initialize still
        // StreamMeta::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_enable_crypto.1 (#31894 / #32122). User-script stream_socket_enable_crypto()
        // stays JitStreamEnableCrypto / StreamMetaJitHelper.
        // __compiler_stream_socket_get_name / __compiler_stream_socket_accept always-on
        // shells removed (#32807): StreamSocketGetNameRuntime / StreamSocketAcceptRuntime
        // own the ABI (getNamedFunction first; Type::initialize still ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mints stream_socket_get_name.1
        // (#31894 / #32122). User-script stream_socket_get_name()/stream_socket_accept()
        // stay JitStreamSocketGetName / JitStreamSocketAccept.
        // __compiler_ftruncate always-on shell removed (#33155): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementI32Bridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // ftruncate.1 (#31894 / #32122). User-script ftruncate() stays JitFtruncate /
        // StreamReadJitHelper (libc force peer #33133).
        // __compiler_ftell always-on shell removed (#33164): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementI64Bridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // ftell.1 (#31894 / #32122). User-script ftell() stays JitFtell /
        // StreamIoJitHelper (php://memory #25299).
        // __compiler_fgetc always-on shell removed (#33166): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementNullableStringBridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // fgetc.1 (#31894 / #32122). User-script fgetc() stays JitFgetc /
        // StreamReadJitHelper (libc force peer #33133).
        // __compiler_fgets always-on shell removed (#33168): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementNullableStringBridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // fgets.1 (#31894 / #32122). User-script fgets() stays JitFgets /
        // StreamReadJitHelper (libc force peer #33133).
        // __compiler_stream_get_line always-on shell removed (#33170): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementStreamGetLineBridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_get_line.1 (#31894 / #32122). User-script stream_get_line() stays
        // JitStreamGetLine / StreamReadJitHelper (libc force peer #33133).
        // __compiler_fseek always-on shell removed (#33176): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementI64Bridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // fseek.1 (#31894 / #32122). User-script fseek() stays JitFseek /
        // StreamReadJitHelper (libc force peer #33133).
        // __compiler_stream_get_contents always-on shell removed (#33178): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementNullableStringBridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_get_contents.1 (#31894 / #32122). User-script stream_get_contents() stays
        // JitStreamGetContents / StreamReadJitHelper (libc force peer #33133).
        // __compiler_stream_copy_to_stream always-on shell removed (#33182): StreamRead /
        // StreamReadRuntime / JitStreamReadBridgeKernel owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementI64Bridge; Type::initialize still
        // StreamRead::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // stream_copy_to_stream.1 (#31894 / #32122). User-script stream_copy_to_stream() stays
        // JitStreamCopyToStream / StreamReadJitHelper (libc force peer #33133).
        // __compiler_get_resource_type always-on shell removed (#33183): StreamResource /
        // JitStreamResourceKernel owns the ABI (getNamedFunction first, then
        // implementIfMissing; Type::initialize still StreamResource::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint get_resource_type.1
        // (#31894 / #32122). User-script get_resource_type() stays JitGetResourceType.
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
        // __compiler_random_bytes always-on shell removed (#33160): StringRandomBytes /
        // RandomBytesJitHelper owns the ABI (getNamedFunction first via
        // JitVmHelperLink::ensureBridge; Type::initialize still
        // StringRandomBytes::ensureLinked). Leftover Type empty decls vs Runtime ABI
        // drift mint random_bytes.1 (#31894 / #32122). User-script random_bytes() stays
        // JitRandomBytes / RandomBytesJitHelper (php-src ext/standard/random.c).
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
        // __compiler_preg_match always-on shell removed (#33187): StringPregMatch /
        // StringPregMatchJit / PregMatchRuntime owns the ABI (getNamedFunction first,
        // then addFunction if absent via implementI64PairBridge; Type::initialize still
        // StringPregMatch::ensureLinked). Leftover Type empty decls vs Runtime ABI drift
        // mint preg_match.1 (#31894 / #32122). User-script preg_match() stays JitPregMatch /
        // PregJitHelper (php-src ext/pcre/php_pcre.c).
        // __compiler_preg_match_all / __compiler_preg_match_ex / __compiler_preg_match_all_ex
        // always-on shells removed (#33188): StringPregMatch / StringPregMatchJit /
        // PregMatchRuntime owns the ABI (getNamedFunction first, then addFunction if absent
        // via implementI64PairBridge / implementMatchExBridge; Type::initialize still
        // StringPregMatch::ensureLinked). Leftover Type empty decls vs Runtime ABI drift mint
        // preg_match_all.1 / preg_match_ex.1 (#31894 / #32122). User-script stays
        // JitPregMatchAll / JitPregMatchEx / JitPregMatchAllEx / PregJitHelper.
        // __compiler_preg_last_error / __compiler_preg_last_error_msg always-on shells
        // removed (#33192): PregMatchRuntime owns both ABIs (getNamedFunction first via
        // implementLastErrorBridge / implementLastErrorMsgBridge; Type::initialize still
        // StringPregMatch::ensureLinked). Leftover Type empty decls vs Runtime ABI drift
        // mint preg_last_error.1 (#31894 / #32122). User-script stays JitPregLastError /
        // JitPregLastErrorMsg (php-src ext/pcre/php_pcre.c).
        // __compiler_preg_replace always-on shell removed (#33191): StringPregMatch /
        // PregMatchRuntime owns the ABI (getNamedFunction first via implementReplaceBridge;
        // Type::initialize still StringPregMatch::ensureLinked). Leftover Type empty decls
        // vs Runtime ABI drift mint preg_replace.1 (#31894 / #32122). User-script
        // preg_replace() stays JitPregReplace / PregJitHelper (php-src ext/pcre/php_pcre.c).
        // __compiler_is_superglobal_name always-on shell removed (#33235): StringSuperglobalName /
        // SuperglobalNameRuntime owns the ABI (getNamedFunction first, then addFunction if
        // absent; Type::initialize still StringSuperglobalName::ensureLinked on the full load
        // path; JitSuperglobalName / JIT.php ensureLinked before lookup). Leftover Type empty
        // decls vs Runtime ABI drift mint is_superglobal_name.1 (#31894 / #32122). User-script
        // stays compiler_is_superglobal_name / JitSuperglobalName / SuperglobalNameJitHelper
        // (php-src Zend/zend_compile.c — zend_is_auto_global_str).
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
        // __compiler_format_datetime always-on shell removed (#33215): StringDateTime
        // owns the ABI (getNamedFunction first via implementFormatDatetimeBridge;
        // Type::initialize still StringDateTime::ensureLinked on the full load path).
        // Leftover Type empty decls vs Runtime ABI drift mint format_datetime.1
        // (#31894 / #32122). User-script date()/gmdate() stay JitDate /
        // FormatDatetimeJitHelper (php-src ext/date/php_date.c).
        // __compiler_strftime always-on shell removed (#33222): StringStrftime
        // owns the ABI (getNamedFunction first via implementStrftimeBridge;
        // Type::initialize still StringStrftime::ensureLinked on the full load path).
        // Leftover Type empty decls vs Runtime ABI drift mint strftime.1
        // (#31894 / #32122). User-script strftime()/gmstrftime() stay JitDate /
        // StrftimeJitHelper (php-src ext/standard/datetime.c).
        // __compiler_strptime always-on shell removed (#33224): StringStrptime
        // owns the ABI (getNamedFunction first via implementStrptimeBridge;
        // Type::initialize still StringStrptime::ensureLinked on the full load path;
        // JitStrptime ensureLinked before lookup). Leftover Type empty decls vs
        // Runtime ABI drift mint strptime.1 (#31894 / #32122). User-script
        // strptime() stays JitStrptime / StrptimeJitHelper (php-src ext/date/php_date.c).
        // __compiler_date_interval_format always-on shell removed (#33203):
        // DateIntervalFormatRuntime owns the ABI (getNamedFunction first via
        // implementFormatBridge; Type::initialize still DateIntervalFormatRuntime::ensureLinked).
        // Leftover Type empty decls vs Runtime ABI drift mint date_interval_format.1
        // (#31894 / #32122). User-script date_interval_format() stays JitDateIntervalFormat
        // / DateIntervalFormatJitHelper (php-src ext/date/php_date.c).
        // __compiler_idate always-on shell removed (#32250): user-script idate()
        // stays JitIdate IR / IdateJitHelper (#26900). StringIdate::implement()
        // is an intentional no-op.
        // __compiler_undefined_array_key_warning_cstr / _long always-on shells removed
        // (#33249): StringTriggerError / JitTriggerErrorKernel owns the ABIs
        // (getNamedFunction first via declareUndefinedArrayKeyAbis / implementUndefKey*Bridge;
        // Type::register declares via owner before HashTable::implement looks them up;
        // Type::initialize still StringTriggerError::ensureLinked on the full load path).
        // Leftover Type empty decls vs Runtime ABI drift mint undefined_array_key_warning_*.1
        // (#31894 / #32122).
        StringTriggerError::declareUndefinedArrayKeyAbis($this->context);
        // __compiler_trigger_error always-on shell removed (#33234): StringTriggerError
        // / JitTriggerErrorKernel owns the ABI (getNamedFunction first via
        // implementTriggerErrorBridge; Type::initialize still StringTriggerError::ensureLinked
        // on the full load path; Context ensureStandaloneBodies + call-site ensureLinked
        // before lookup). Leftover Type empty decls vs Runtime ABI drift mint
        // trigger_error.1 (#31894 / #32122). User-script trigger_error()/user_error()
        // stay trigger_error_ / JitBuiltinWarning (php-src Zend/zend_execute_API.c,
        // main/php_errors.c, ext/standard/basic_functions.c).
        // __compiler_assert_fail / __compiler_assert_fail_string always-on shells
        // removed (#33237 / #33241): AssertFail owns both ABIs (getNamedFunction
        // first, then addFunction if absent; Type::initialize still
        // AssertFail::ensureLinked on the full load path; JitAssert ensureLinked
        // before lookup). Leftover Type empty decls vs Runtime ABI drift mint
        // assert_fail.1 / assert_fail_string.1 (#31894 / #32122). User-script
        // assert() stays JitAssert (php-src ext/standard/assert.c).
        // __compiler_assert_options always-on shell removed (#33245): AssertOptionsRuntime
        // owns the ABI (getNamedFunction first, then addFunction if absent; Type::initialize
        // still AssertOptionsRuntime::ensureLinked on the full load path; JitAssertOptions
        // ensureLinked before lookup). Leftover Type empty decls vs Runtime ABI drift mint
        // assert_options.1 (#31894 / #32122). User-script assert_options() stays
        // JitAssertOptions (php-src ext/standard/assert.c).
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
        // __compiler_preg_split always-on shell removed (#33199): StringPregMatch /
        // PregMatchRuntime owns the ABI (getNamedFunction first via implementSplitBridge;
        // Type::initialize still StringPregMatch::ensureLinked). Leftover Type empty decls
        // vs Runtime ABI drift mint preg_split.1 (#31894 / #32122). User-script
        // preg_split() stays JitPregSplit / PregJitHelper (php-src ext/pcre/php_pcre.c).
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
        // __compiler_fgetcsv always-on shell removed (#33189): StringStreamCsv /
        // StringFgetcsvJit owns the ABI (getNamedFunction first, then addFunction if
        // absent via implementFgetcsvBridge; Type::initialize still
        // StringStreamCsv::ensureLinked on the full load path). Leftover Type empty
        // decls vs Runtime ABI drift mint fgetcsv.1 (#31894 / #32122). User-script
        // fgetcsv() stays JitFgetcsv / CsvStrGetcsvJitHelper (php-src file.c).
        // __compiler_str_getcsv always-on shell removed (#33196): StringStrGetcsv /
        // StringStreamCsv owns the ABI (getNamedFunction first via implementStrGetcsvBridge;
        // Type::initialize still StringStreamCsv::ensureLinked → StringStrGetcsv). Leftover
        // Type empty decls vs Runtime ABI drift mint str_getcsv.1 (#31894 / #32122).
        // User-script str_getcsv() stays JitStrGetcsv / CsvStrGetcsvJitHelper
        // (php-src ext/standard/file.c — PHP_FUNCTION(str_getcsv)).
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $i64 = $this->context->getTypeFromString('int64');
        // __phpc_parse_url_component / __phpc_parse_url_assoc always-on shells removed (#33236):
        // ParseUrlRuntime owns the ABI (getNamedFunction first via implementIfMissing +
        // scopeLoweringToFunction #33226; Type::initialize still ParseUrlRuntime::ensureLinked;
        // JitParseUrl ensureLinked before lookup). Leftover Type empty decls vs Runtime ABI
        // drift mint parse_url_component.1 (#31894 / #32122). User-script parse_url() stays
        // JitParseUrl / ParseUrlJitHelper (php-src ext/standard/url.c).
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
        // __compiler_serialize_hashtable / __compiler_serialize_value /
        // __compiler_serialize_object always-on shells removed (#33207): StringSerialize
        // owns the ABI (getNamedFunction first via bridges / JitVmHelperLink::ensureBridge;
        // Type::initialize still StringSerialize::ensureLinked on the full load path).
        // Leftover Type empty decls vs Runtime ABI drift mint serialize_hashtable.1
        // (#31894 / #32122). User-script serialize() stays JitSerialize /
        // SerializeNestedJitHelper (php-src ext/standard/var.c).
        // __compiler_unserialize always-on shell removed (#33213): StringUnserialize
        // owns the ABI (getNamedFunction first via implementUnserializeBridge;
        // Type::initialize still StringUnserialize::ensureLinked). Leftover Type empty
        // decls vs Runtime ABI drift mint unserialize.1 (#31894 / #32122). User-script
        // unserialize() stays JitUnserialize / UnserializeJitHelper
        // (php-src ext/standard/var.c / var_unserializer.re).
        // __compiler_shell_exec / __compiler_escapeshellarg / __compiler_escapeshellcmd
        // always-on shells removed (#33201): ProcessRuntime owns the ABI (getNamedFunction
        // first via implementNullableStringBridge / implementStringBridge; Type::initialize
        // still ProcessRuntime::ensureLinked). Leftover Type empty decls vs Runtime ABI
        // drift mint shell_exec.1 (#31894 / #32122). User-script stays JitShellExec /
        // JitEscapeshellarg / JitEscapeshellcmd (php-src ext/standard/exec.c).
        // __compiler_phpc_run_command always-on shell removed (#33212): ProcessRuntime
        // owns the ABI (getNamedFunction first via ensurePhpcRunCommandLinked /
        // implementPhpcRunCommandBridge; JitPhpcRunCommand ensureLinked before lookup).
        // Leftover Type empty decls vs Runtime ABI drift mint phpc_run_command.1
        // (#31894 / #32122). User-script stays JitPhpcRunCommand / ProcessPhpcRunCommandJitHelper.
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
        // __compiler_http_build_query always-on shell removed (#33208): StringHttpBuildQuery
        // owns the ABI (getNamedFunction first via implementBuildBridge; String_::implement
        // + JitHttpBuildQuery / http_build_query.php ensureLinked before lookup). Leftover
        // Type empty decls vs Runtime ABI drift mint http_build_query.1 (#31894 / #32122).
        // User-script http_build_query() stays JitHttpBuildQuery / HttpBuildQueryJitHelper
        // (php-src ext/standard/http.c — PHP_FUNCTION(http_build_query)).
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
        StringDateTime::ensureLinked($this->context);
        StringDeployPath::ensureLinked($this->context);
        StringSuperglobalName::ensureLinked($this->context);
        StringStrftime::ensureLinked($this->context);
        StringStrptime::ensureLinked($this->context);
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
        StringRandomBytes::ensureLinked($this->context);
        PasswordCryptoRuntime::ensureLinked($this->context);
        StringHashCrypto::ensureLinked($this->context);
        StringPregMatch::ensureLinked($this->context);
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
        StringFilePutContents::ensureLinked($this->context);
        MimeContentTypeRuntime::ensureLinked($this->context);
        MetaTagsRuntime::ensureLinked($this->context);
        StringErrorLog::ensureLinked($this->context);
        GetHeadersRuntime::ensureLinked($this->context);
        ParseUrlRuntime::ensureLinked($this->context);
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
        StringStreamCsv::ensureLinked($this->context);
        StringSerialize::ensureLinked($this->context);
        StringUnserialize::ensureLinked($this->context);
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
