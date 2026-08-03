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
        $i32p = $i32->pointerType(0);

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'malloc' => [$i8p, false, [$sizeT]],
            'calloc' => [$i8p, false, [$sizeT, $sizeT]],
            'realloc' => [$i8p, false, [$i8p, $sizeT]],
            'free' => [$void, false, [$i8p]],
            'memcpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'memmove' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'memset' => [$i8p, false, [$i8p, $i32, $sizeT]],
            'memcmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'memchr' => [$i8p, false, [$i8p, $i32, $sizeT]],
            'strlen' => [$sizeT, false, [$i8p]],
            'strcmp' => [$i32, false, [$i8p, $i8p]],
            'strncmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'strcasecmp' => [$i32, false, [$i8p, $i8p]],
            'strncasecmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'strcoll' => [$i32, false, [$i8p, $i8p]],
            'strspn' => [$sizeT, false, [$i8p, $i8p]],
            'strcspn' => [$sizeT, false, [$i8p, $i8p]],
            'strchr' => [$i8p, false, [$i8p, $i32]],
            'strstr' => [$i8p, false, [$i8p, $i8p]],
            'strrchr' => [$i8p, false, [$i8p, $i32]],
            'strpbrk' => [$i8p, false, [$i8p, $i8p]],
            'strncpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'strtol' => [$i64, false, [$i8p, $i8pp, $i32]],
            'strtod' => [$dbl, false, [$i8p, $i8pp]],
            'strdup' => [$i8p, false, [$i8p]],
            'strtok_r' => [$i8p, false, [$i8p, $i8p, $i8pp]],
            'fopen' => [$i8p, false, [$i8p, $i8p]],
            'fread' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
            'fwrite' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
            'fclose' => [$i32, false, [$i8p]],
            'fflush' => [$i32, false, [$i8p]],
            'ferror' => [$i32, false, [$i8p]],
            'fgets' => [$i8p, false, [$i8p, $i32, $i8p]],
            'open' => [$i32, false, [$i8p, $i32, $i32]],
            'close' => [$i32, false, [$i32]],
            'read' => [$i64, false, [$i32, $i8p, $i64]],
            'write' => [$i64, false, [$i32, $i8p, $i64]],
            'stat' => [$i32, false, [$i8p, $i8p]],
            'access' => [$i32, false, [$i8p, $i32]],
            'lstat' => [$i32, false, [$i8p, $i8p]],
            'chmod' => [$i32, false, [$i8p, $i32]],
            'utime' => [$i32, false, [$i8p, $i8p]],
            'mkstemp' => [$i32, false, [$i8p]],
            'chown' => [$i32, false, [$i8p, $i32, $i32]],
            'fchownat' => [$i32, false, [$i32, $i8p, $i32, $i32, $i32]],
            'getgrnam' => [$i8p, false, [$i8p]],
            'getpwnam' => [$i8p, false, [$i8p]],
            'mkdir' => [$i32, false, [$i8p, $i32]],
            'remove' => [$i32, false, [$i8p]],
            'rename' => [$i32, false, [$i8p, $i8p]],
            'chdir' => [$i32, false, [$i8p]],
            'gethostname' => [$i32, false, [$i8p, $sizeT]],
            'getenv' => [$i8p, false, [$i8p]],
            'putenv' => [$i32, false, [$i8p]],
            'setenv' => [$i32, false, [$i8p, $i8p, $i32]],
            'unsetenv' => [$i32, false, [$i8p]],
            'realpath' => [$i8p, false, [$i8p, $i8p]],
            'time' => [$i64, false, [$i8p]],
            'printf' => [$i32, true, [$i8p]],
            'snprintf' => [$i32, true, [$i8p, $sizeT, $i8p]],
            'sscanf' => [$i32, true, [$i8p, $i8p]],
            'popen' => [$i8p, false, [$i8p, $i8p]],
            'pclose' => [$i32, false, [$i8p]],
            'pipe' => [$i32, false, [$i32p]],
            'fork' => [$i32, false, []],
            'dup2' => [$i32, false, [$i32, $i32]],
            'waitpid' => [$i32, false, [$i32, $i32p, $i32]],
            '__phpc_resolve_stream' => [$i8p, false, [$i64]],
            'fileno' => [$i32, false, [$i8p]],
            'fsync' => [$i32, false, [$i32]],
            'fdatasync' => [$i32, false, [$i32]],
            'flock' => [$i32, false, [$i32, $i32]],
            'pow' => [$dbl, false, [$dbl, $dbl]],
            'nextafter' => [$dbl, false, [$dbl, $dbl]],
            'hypot' => [$dbl, false, [$dbl, $dbl]],
            'fmod' => [$dbl, false, [$dbl, $dbl]],
            'ceil' => [$dbl, false, [$dbl]],
            'cos' => [$dbl, false, [$dbl]],
            'cosh' => [$dbl, false, [$dbl]],
            'sin' => [$dbl, false, [$dbl]],
            'sinh' => [$dbl, false, [$dbl]],
            'tan' => [$dbl, false, [$dbl]],
            'tanh' => [$dbl, false, [$dbl]],
            'acos' => [$dbl, false, [$dbl]],
            'asin' => [$dbl, false, [$dbl]],
            'acosh' => [$dbl, false, [$dbl]],
            'asinh' => [$dbl, false, [$dbl]],
            'atanh' => [$dbl, false, [$dbl]],
            'exp' => [$dbl, false, [$dbl]],
            'log' => [$dbl, false, [$dbl]],
            'log10' => [$dbl, false, [$dbl]],
            'floor' => [$dbl, false, [$dbl]],
            'sqrt' => [$dbl, false, [$dbl]],

            // VmFloatCompare NestedJIT (#21109 / #9976) — math.h
            'isnan' => [$i32, false, [$dbl]],
            'isinf' => [$i32, false, [$dbl]],
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

    private static function implementMemmoveBody(Context $context): void
    {
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
