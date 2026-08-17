<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Canonical C library extern declarations for AOT/MCJIT modules.
 *
 * Registers malloc/free and string/memory helpers with int8* pointer types so
 * per-builtin ensureLibc() helpers cannot introduce conflicting void* signatures.
 */
final class LibcExtern
{
    public static function register(Context $context): void
    {
        $ctx = $context->context;
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'malloc' => [$i8p, false, [$sizeT]],
            'realloc' => [$i8p, false, [$i8p, $sizeT]],
            'free' => [$void, false, [$i8p]],
            'memcpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            // memmove dropped (#31743): JitParseStrUserScriptCstrKernel::ensureLibc declares
            // memmove(3) module-locally (sole NestedJIT lookupFunction consumer); EMBED MCJIT
            // still gets implementMemmoveBody after ensureMemmoveDecl (#98 / #21109).
            'memset' => [$i8p, false, [$i8p, $i32, $sizeT]],
            'memcmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            // memchr dropped (#31655): JitTempnamKernel::ensureLibc declares memchr(3)
            // module-locally (sole NestedJIT lookupFunction consumer); user-script tempnam()
            // stays on TempnamJitHelper / StringTempnam / VmFsTempnam* (not libc).
            'strlen' => [$sizeT, false, [$i8p]],
            'strcmp' => [$i32, false, [$i8p, $i8p]],
            'strncmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            // strcasecmp dropped (#31787): NestedJIT class/name compares look up
            // __compiler_strcasecmp (StringCaseCompare::ensureStrcasecmpLinked);
            // user-script strcasecmp() stays on CaseCompareJitHelper / VmString
            // (#15225 / #26861) — not libc. Peer strncasecmp drop (#31682).
            // strncasecmp dropped (#31682): Type/Object_::classIdFromRuntimeName +
            // JitFilter::parseBooleanStringToken look up __compiler_strncasecmp
            // (StringCaseCompare::ensureStrncasecmpLinked); user-script strncasecmp()
            // stays on CaseCompareJitHelper / VmString (#15225 / #26861) — not libc.
            // strcoll dropped (#31498): StringStrcoll declares strcoll(3) module-locally
            // for the __compiler_strcoll trampoline (#27059); no other lookupFunction remains.
            // strcspn dropped (#29050): parse_str AOT kernel + StringStrspn use __compiler_strcspn.
            // strchr dropped (#31519): NestedJIT StringGetenv putenv leaf + JitParseStrUserScriptCstrKernel
            // declare strchr(3) module-locally; PHP strchr()/strstr() are VmString (not libc).
            // strstr dropped (#31519): no remaining lookupFunction('strstr') consumers
            // (user-script strstr/strchr already PHP helpers — peer BootstrapCompileSmokeM3EmitShrinkTest).
            // strrchr dropped (#31458): StrrchrJitHelper owns user-script strrchr();
            // NestedJIT JitTempnamKernel + ReflectionSetup declare strrchr(3) module-locally.
            'strtol' => [$i64, false, [$i8p, $i8pp, $i32]],
            'strtod' => [$dbl, false, [$i8p, $i8pp]],
            // strdup dropped from always-on (#31534): JitFsGlobKernel / JitStreamIoKernel declare
            // strdup(3) module-locally (#31721 GlobIterator/FilesystemIterator AOT).
            // strtok_r dropped (#29091): parse_str AOT kernel uses __compiler_strtok_r.
            // fopen/fread/fwrite/fclose dropped (#31764): JitStreamIoKernel::ensureLibc already
            // declares FILE* ops module-locally; JitFilePutContentsLibc / JitMultipartKernel /
            // M5TrivialEchoNative call ensureStdioFile() before lookup. User-script fopen()
            // stays on JitStreamIoKernel / __compiler_fopen / StreamIoJitHelper (#5343 / #26929).
            // fflush/ferror/fgets dropped (#31606): JitStreamIoKernel / JitStreamSyncKernel /
            // ObStorageLlvm declare module-locally; user-script builtins stay on PHP helpers.
            'open' => [$i32, false, [$i8p, $i32, $i32]],
            'close' => [$i32, false, [$i32]],
            'read' => [$i64, false, [$i32, $i8p, $i64]],
            'write' => [$i64, false, [$i32, $i8p, $i64]],
            // mkstemp dropped (#31655): JitTempnamKernel::ensureLibc declares mkstemp(3)
            // module-locally (sole NestedJIT lookupFunction consumer); user-script tempnam()
            // stays on TempnamJitHelper / StringTempnam / VmFsTempnam* (not libc).
            // chmod dropped (#31374): ChmodJitHelper / StringChmod own user-script chmod();
            // NestedJIT JitTempnamKernel + M5TrivialEchoNative declare chmod(2) module-locally.
            // mkdir dropped (#31374): MkdirJitHelper / StringMkdir own user-script mkdir();
            // NestedJIT JitSessionStorageKernel declares mkdir(2) module-locally.
            // rename dropped (#29090): StringRename NestedJIT leaf declares rename(2)
            // module-locally; RenameJitHelper SSOT is VmFs::rename (Unlink #19186 shape).
            // chdir dropped (#29219): StringChdir NestedJIT leaf declares chdir(2)
            // module-locally; ChdirJitHelper uses @chdir (Rename #29141 shape).
            // chroot dropped (#30558): StringChroot NestedJIT leaf declares chroot(2)
            // module-locally; ChrootJitHelper uses @chroot (chdir #29219 shape).
            // getcwd NestedJIT leaf (#29429): GetcwdJit declares getcwd(2) module-locally;
            // GetcwdJitHelper uses @getcwd (never lived in this table — always-on realpath LLVM removed).
            // stat/access/lstat dropped (#31403): JitStatKernel / TouchLibcRuntime / FtokRuntime /
            // JitFsGlobKernel declare module-locally; StatPathJitHelper owns user-script path predicates.
            // getenv dropped (#31637): StringGetenv::ensureLibcGetenv + SysGetTempDir /
            // BootstrapCompileSmokeM3Emit / VmDriverExecuteNative / M3EmitTuTrivialEchoAot /
            // session+CGI kernels declare getenv(3) module-locally; user-script getenv()
            // stays on GetenvLookupJitHelper / StringGetenv NestedJIT leaf (#29313).
            // putenv dropped (#31582): BootstrapCompileSmokeM3Emit declares putenv(3)
            // module-locally (ensureLibcPutenv); user-script putenv() stays on PutenvJitHelper
            // / NestedJIT invokePutenvNestedLeaf (setenv/unsetenv #31558) — not this row.
            // setenv/unsetenv dropped (#31558): StringGetenv NestedJIT putenv leaf declares
            // setenv(3)/unsetenv(3) module-locally (#29334 / ensureLibcStrchr peer #31519);
            // user-script putenv()/getenv() stay on PutenvJitHelper / GetenvLookupJitHelper.
            // realpath dropped (#31534 / #30530 peer): SysGetTempDirRuntime NestedJIT leaf
            // declares realpath(3) module-locally (#29433); StringRealpath / RealpathJitHelper
            // own user-script realpath() (VmString PHP — not libc). Module always-on already
            // dropped realpath (#30530).
            // time dropped (#30332): StringTime + TimeJitHelper; TouchLibcRuntime routes via
            // StringTime::invoke (#30472) — no module-local time(2) decl.
            // printf dropped (#31706): NestedJIT JitHeader / JitSetcookie / JitSessionStorageKernel /
            // ScriptExit declare printf(3) module-locally via ensurePrintf(); user-script printf()
            // stays on JitPrintf / __compiler_printf / printf_ (#3681) — not libc.
            'snprintf' => [$i32, true, [$i8p, $sizeT, $i8p]],
            // popen/pclose/fileno dropped (#31606): JitStreamIoKernel / JitStreamSyncKernel
            // declare module-locally (peer fflush/ferror/fgets above); user-script popen/pclose
            // stay on PHP helpers (`ext/standard` + `__compiler_*`).
            '__phpc_resolve_stream' => [$i8p, false, [$i64]],
            // Math libc decls removed (#28808): userland Math* + NestedJIT helpers are
            // PHP SSOT (MathSqrt #27888 … MathNextafter #28716); stats_standard_deviation
            // routes through MathSqrt::invoke.
            // Dead FS/string/process decls removed (#28850): ChownRuntime / StringStrspn /
            // StringStrpbrk / sync helpers own PHP or phpc_* ABIs — no lookupFunction remains.
            // libc strcspn removed (#29050): last consumer was JitParseStrUserScriptCstrKernel.

            // x86_64 SYS_* trampoline — MCJIT relocates varargs libc better than write(2) (#21109)
            'syscall' => [$i64, true, [$i64]],
            // Host aliases — custom names so MCJIT resolves via LLVMAddSymbol (#21124, #98).
            // php_write is ob-aware (PHPUnit ob_start / SAPI); libc write(2) is not.
            '__phpc_host_php_write' => [$sizeT, false, [$i8p, $sizeT]],
            '__phpc_host_snprintf' => [$i32, true, [$i8p, $sizeT, $i8p]],
        ];

        foreach ($specs as $name => [$ret, $vararg, $params]) {
            self::ensure($context, $name, $ctx->functionType($ret, $vararg, ...$params));
        }
    }

    /**
     * Module-local fopen/fread/fwrite/fclose after LibcExtern always-on drop (#31764).
     *
     * User-script fopen() stays on JitStreamIoKernel / __compiler_fopen (#5343);
     * NestedJIT FILE* leaves call this before lookupFunction('fopen') etc.
     */
    public static function ensureStdioFile(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        // Tuple list (not always-on table rows) so LibcExternDeadDeclsRuntimeShrinkTest can
        // assert fopen/fread/fwrite/fclose rows are gone without matching this helper.
        foreach ([
            ['fopen', $i8p, [$i8p, $i8p]],
            ['fread', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fwrite', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fclose', $i32, [$i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);

                continue;
            } catch (\LogicException $e) {
            }
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }
    }

    /**
     * Module-local printf(3) after LibcExtern always-on drop (#31706).
     *
     * User-script printf() stays on JitPrintf / __compiler_printf (#3681); NestedJIT
     * header/cookie/echo emitters call this before lookupFunction('printf').
     */
    public static function ensurePrintf(Context $context): void
    {
        try {
            $context->lookupFunction('printf');

            return;
        } catch (\LogicException $e) {
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->getNamedFunction('printf');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'printf',
                $context->context->functionType($i32, true, $i8p)
            );
        }
        $context->registerFunction('printf', $fn);
    }

    /**
     * Define IR bodies for memset/memcpy/memmove on EMBED MCJIT (#98, #2055, #21109).
     *
     * Declared-only libc mem* often relocate to null under LLVM 9 MCJIT even after
     * LoadLibraryPermanently; module-local loops keep string/hashtable init safe.
     */
    public static function implementMcjitMemBodies(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        self::implementMemsetBody($context);
        self::implementMemcpyBody($context);
        self::implementMemmoveBody($context);
        self::implementMemcmpBody($context);
        self::implementStrlenBody($context);
        self::implementStrcmpBody($context);
        self::implementWriteViaHostAlias($context);
    }

    /**
     * MCJIT fails to relocate libc `write` (null call) while varargs libc
     * (snprintf/syscall) resolves (#98, #21109). Implement write via SYS_write.
     */
    private static function implementWriteViaHostAlias(Context $context): void
    {
        $fn = $context->module->getNamedFunction('write');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $syscall = $context->module->getNamedFunction('syscall');
        if (null === $syscall) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('write_syscall_entry');
        $b->positionAtEnd($entry);
        $fd64 = $b->zExt($fn->getParam(0), $i64);
        // SYS_write = 1 on x86_64 Linux
        $ret = $b->call(
            $syscall,
            $i64->constInt(1, false),
            $fd64,
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $b->returnValue($ret);
        $context->registerFunction('write', $fn);
        unset($i32);
    }

    private static function implementMemsetBody(Context $context): void
    {
        $fn = $context->module->getNamedFunction('memset');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('memset_entry');
        $loop = $fn->appendBasicBlock('memset_loop');
        $body = $fn->appendBasicBlock('memset_body');
        $done = $fn->appendBasicBlock('memset_done');
        $b->positionAtEnd($entry);
        $dst = $fn->getParam(0);
        $byte = $b->truncOrBitCast($fn->getParam(1), $i8);
        $len = $fn->getParam(2);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idx);
        $cont = $b->icmp(\PHPLLVM\Builder::INT_ULT, $i, $len);
        $b->branchIf($cont, $body, $done);
        $b->positionAtEnd($body);
        $ptr = $b->gep($dst, $i);
        $b->store($byte, $ptr);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branch($loop);
        $b->positionAtEnd($done);
        $b->returnValue($dst);
        $context->registerFunction('memset', $fn);
    }

    private static function implementMemcpyBody(Context $context): void
    {
        $fn = $context->module->getNamedFunction('memcpy');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('memcpy_entry');
        $loop = $fn->appendBasicBlock('memcpy_loop');
        $body = $fn->appendBasicBlock('memcpy_body');
        $done = $fn->appendBasicBlock('memcpy_done');
        $b->positionAtEnd($entry);
        $dst = $fn->getParam(0);
        $src = $fn->getParam(1);
        $len = $fn->getParam(2);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idx);
        $cont = $b->icmp(\PHPLLVM\Builder::INT_ULT, $i, $len);
        $b->branchIf($cont, $body, $done);
        $b->positionAtEnd($body);
        $srcPtr = $b->gep($src, $i);
        $dstPtr = $b->gep($dst, $i);
        $b->store($b->load($srcPtr), $dstPtr);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branch($loop);
        $b->positionAtEnd($done);
        $b->returnValue($dst);
        $context->registerFunction('memcpy', $fn);
    }

    /**
     * Module-local memmove(3) after LibcExtern always-on drop (#31743).
     *
     * EMBED MCJIT still needs a declared symbol before {@see implementMemmoveBody};
     * NestedJIT parse_str also calls this via ensureLibc before lookup.
     */
    public static function ensureMemmoveDecl(Context $context): void
    {
        try {
            $context->lookupFunction('memmove');

            return;
        } catch (\LogicException $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = $context->module->getNamedFunction('memmove');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'memmove',
                $context->context->functionType($i8p, false, $i8p, $i8p, $sizeT)
            );
        }
        $context->registerFunction('memmove', $fn);
    }

    private static function implementMemmoveBody(Context $context): void
    {
        self::ensureMemmoveDecl($context);
        $fn = $context->module->getNamedFunction('memmove');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        // Non-overlapping-safe forward copy is enough for compiler runtime uses;
        // overlapping dest>src still needs backward copy for full memmove semantics.
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('memmove_entry');
        $fwdSetup = $fn->appendBasicBlock('memmove_fwd_setup');
        $fwdLoop = $fn->appendBasicBlock('memmove_fwd_loop');
        $fwdBody = $fn->appendBasicBlock('memmove_fwd_body');
        $bwdSetup = $fn->appendBasicBlock('memmove_bwd_setup');
        $bwdLoop = $fn->appendBasicBlock('memmove_bwd_loop');
        $bwdBody = $fn->appendBasicBlock('memmove_bwd_body');
        $done = $fn->appendBasicBlock('memmove_done');
        $b->positionAtEnd($entry);
        $dst = $fn->getParam(0);
        $src = $fn->getParam(1);
        $len = $fn->getParam(2);
        $dstInt = $b->ptrToInt($dst, $i64);
        $srcInt = $b->ptrToInt($src, $i64);
        $dstAfterSrc = $b->icmp(\PHPLLVM\Builder::INT_UGT, $dstInt, $srcInt);
        $b->branchIf($dstAfterSrc, $bwdSetup, $fwdSetup);

        $b->positionAtEnd($fwdSetup);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($fwdLoop);
        $b->positionAtEnd($fwdLoop);
        $i = $b->load($idx);
        $cont = $b->icmp(\PHPLLVM\Builder::INT_ULT, $i, $len);
        $b->branchIf($cont, $fwdBody, $done);
        $b->positionAtEnd($fwdBody);
        $b->store($b->load($b->gep($src, $i)), $b->gep($dst, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branch($fwdLoop);

        $b->positionAtEnd($bwdSetup);
        $j = $b->alloca($i64);
        $b->store($len, $j);
        $b->branch($bwdLoop);
        $b->positionAtEnd($bwdLoop);
        $cur = $b->load($j);
        $more = $b->icmp(\PHPLLVM\Builder::INT_UGT, $cur, $i64->constInt(0, false));
        $b->branchIf($more, $bwdBody, $done);
        $b->positionAtEnd($bwdBody);
        $next = $b->sub($cur, $i64->constInt(1, false));
        $b->store($b->load($b->gep($src, $next)), $b->gep($dst, $next));
        $b->store($next, $j);
        $b->branch($bwdLoop);

        $b->positionAtEnd($done);
        $b->returnValue($dst);
        $context->registerFunction('memmove', $fn);
        unset($i8, $i8p);
    }

    private static function implementMemcmpBody(Context $context): void
    {
        $fn = $context->module->getNamedFunction('memcmp');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('memcmp_entry');
        $loop = $fn->appendBasicBlock('memcmp_loop');
        $body = $fn->appendBasicBlock('memcmp_body');
        $eqNext = $fn->appendBasicBlock('memcmp_eq_next');
        $diff = $fn->appendBasicBlock('memcmp_diff');
        $done = $fn->appendBasicBlock('memcmp_done');
        $b->positionAtEnd($entry);
        $a = $fn->getParam(0);
        $c = $fn->getParam(1);
        $len = $fn->getParam(2);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idx);
        $cont = $b->icmp(\PHPLLVM\Builder::INT_ULT, $i, $len);
        $b->branchIf($cont, $body, $done);
        $b->positionAtEnd($body);
        $av = $b->load($b->gep($a, $i));
        $bv = $b->load($b->gep($c, $i));
        $ne = $b->icmp(\PHPLLVM\Builder::INT_NE, $av, $bv);
        $b->branchIf($ne, $diff, $eqNext);
        $b->positionAtEnd($eqNext);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branch($loop);
        $b->positionAtEnd($diff);
        $ai = $b->intCast($av, $i32);
        $bi = $b->intCast($bv, $i32);
        $b->returnValue($b->sub($ai, $bi));
        $b->positionAtEnd($done);
        $b->returnValue($i32->constInt(0, false));
        $context->registerFunction('memcmp', $fn);
        unset($i8);
    }

    private static function implementStrlenBody(Context $context): void
    {
        $fn = $context->module->getNamedFunction('strlen');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('strlen_entry');
        $loop = $fn->appendBasicBlock('strlen_loop');
        $body = $fn->appendBasicBlock('strlen_body');
        $done = $fn->appendBasicBlock('strlen_done');
        $b->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idx);
        $ch = $b->load($b->gep($s, $i));
        $isZero = $b->icmp(\PHPLLVM\Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $b->branchIf($isZero, $done, $body);
        $b->positionAtEnd($body);
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branch($loop);
        $b->positionAtEnd($done);
        $b->returnValue($b->load($idx));
        $context->registerFunction('strlen', $fn);
    }

    private static function implementStrcmpBody(Context $context): void
    {
        $fn = $context->module->getNamedFunction('strcmp');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('strcmp_entry');
        $loop = $fn->appendBasicBlock('strcmp_loop');
        $next = $fn->appendBasicBlock('strcmp_next');
        $diff = $fn->appendBasicBlock('strcmp_diff');
        $done = $fn->appendBasicBlock('strcmp_done');
        $b->positionAtEnd($entry);
        $a = $fn->getParam(0);
        $c = $fn->getParam(1);
        $idx = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($loop);
        $b->positionAtEnd($loop);
        $i = $b->load($idx);
        $av = $b->load($b->gep($a, $i));
        $bv = $b->load($b->gep($c, $i));
        $ne = $b->icmp(\PHPLLVM\Builder::INT_NE, $av, $bv);
        $b->branchIf($ne, $diff, $next);
        $b->positionAtEnd($next);
        $isZero = $b->icmp(\PHPLLVM\Builder::INT_EQ, $av, $i8->constInt(0, false));
        $b->store($b->add($i, $i64->constInt(1, false)), $idx);
        $b->branchIf($isZero, $done, $loop);
        $b->positionAtEnd($diff);
        $ai = $b->intCast($av, $i32);
        $bi = $b->intCast($bv, $i32);
        $b->returnValue($b->sub($ai, $bi));
        $b->positionAtEnd($done);
        $b->returnValue($i32->constInt(0, false));
        $context->registerFunction('strcmp', $fn);
    }

    private static function ensure(Context $context, string $name, $fnType): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        try {
            $context->lookupFunction($name);

            return;
        } catch (\Throwable) {
        }
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);
    }
}
