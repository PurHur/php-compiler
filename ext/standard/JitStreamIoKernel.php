<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StreamFilter;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM stream I/O for standalone / user-script AOT — fopen/fread/fwrite/tmpfile (#5343, #19462, #19530, #26929, #33145).
 *
 * Embed JIT uses {@see \PHPCompiler\JIT\Builtin\StreamIoRuntime} + {@see StreamIoJitHelper} PHP.
 * User-script AOT cannot nested-JIT VmFs helpers (ExternalMethod → handle 0, #16075) —
 * this libc + {@see StreamGlobalsJit} handle-table path is the user-script SSOT.
 * Restored after #20943 NestedJIT-only regression blocked fsync/fwrite under thin AOT (#26929).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19500 / #19466.
 *
 * Do not re-add empty always-on shells in Builtin\Type — leftover decls mint
 * stream_supports.1 (#31894 / #32122). Type::initialize still StreamIo::ensureLinked.
 *
 * php-src: ext/standard/file.c, ext/standard/streamsfuncs.c
 */
final class JitStreamIoKernel
{
    private const MAX_HANDLES = 256;

    private const DEFAULT_CHUNK_SIZE = 8192;

    private const DEFAULT_BUFFER_SIZE = 8192;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    private const GLOBAL_WAS_USED = 'phpc_stream_was_used';

    private const GLOBAL_IS_POPEN = 'phpc_stream_is_popen';

    private const GLOBAL_CHUNK_SIZE = 'phpc_stream_chunk_size';

    private const GLOBAL_WRITE_BUFFER = 'phpc_stream_write_buffer';

    private const GLOBAL_READ_BUFFER = 'phpc_stream_read_buffer';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_fwrite',
        '__compiler_fopen',
        '__compiler_popen',
        '__compiler_tmpfile',
        '__compiler_fread',
        '__compiler_fgets',
        '__compiler_stream_supports',
    ];

    /**
     * Upgrade inventory defer stubs to real libc bridges for user-script AOT (#19462, #9142).
     */
    public static function implementForUserScriptLowering(Context $context): void
    {
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        self::clearDeferStubs($context);
        self::ensureStreamGlobals($context);
        StreamFilter::ensureLinked($context);
        // Inventory may have left apply_* as ret i32 0 on a __string__* decl (#19462).
        self::ensureIdentityStreamFilterApply($context);
        StreamGlobalsJit::implement($context);

        self::implementIfMissing($context, '__compiler_fwrite', self::emitFwrite(...));
        self::implementIfMissing($context, '__phpc_try_fopen_stdio', self::emitTryFopenStdio(...));
        self::implementIfMissing($context, '__phpc_try_fopen_php_memory', self::emitTryFopenPhpMemory(...));
        // RFC2397 data:// before plain libc fopen (#34744 / peer #34731 file_get_contents).
        \PHPCompiler\JIT\Builtin\StringBase64Decode::ensureLinked($context);
        self::implementIfMissing($context, '__phpc_try_fopen_data_uri', self::emitTryFopenDataUri(...));
        self::implementIfMissing($context, '__phpc_php_fopen_plain', self::emitPhpFopenPlain(...));
        self::implementIfMissing($context, '__compiler_fopen', self::emitFopen(...));
        self::implementIfMissing($context, '__compiler_popen', self::emitPopen(...));
        self::implementIfMissing($context, '__compiler_tmpfile', self::emitTmpfile(...));
        self::implementIfMissing($context, '__compiler_fread', self::emitFread(...));
        self::implementFgetsForce($context);
        self::implementFseekForce($context);
        self::implementFtellForce($context);
        self::implementStreamGetContentsForce($context);
        self::implementIfMissing($context, '__compiler_stream_supports', self::emitStreamSupports(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function clearDeferStubs(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 1 !== $fn->countBasicBlocks()) {
                continue;
            }
            $isEntryStub = false;
            foreach ($fn->getBasicBlocks() as $block) {
                $isEntryStub = 'entry' === $block->getName();
                break;
            }
            if (!$isEntryStub) {
                continue;
            }
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }
    }

    /**
     * Force identity bodies for stream-filter apply ABI — inventory stubs used
     * `ret i32 0` after ensureLibc declared `__string__*` return (#19462).
     */
    private static function ensureIdentityStreamFilterApply(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64, $strPtr);

        foreach (['__compiler_stream_filter_apply_write', '__compiler_stream_filter_apply_read'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction($name, $ft);
            }
            if ($fn->countBasicBlocks() > 0) {
                foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                    $block->delete();
                }
            }
            $entry = $fn->appendBasicBlock('stream_filter_apply_identity');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue($fn->getParam(1));
            $context->registerFunction($name, $fn);
        }
        $context->builder->clearInsertionPosition();
    }

    /** Stream handle globals for stream_socket_pair() without pulling full I/O emitters (#3437). */
    public static function ensureStreamGlobals(Context $context): void
    {
        self::ensureExternGlobals($context);
        self::ensureLibc($context);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $fn = match ($name) {
            '__compiler_fwrite' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            ),
            '__compiler_fopen' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__compiler_popen' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            ),
            '__compiler_tmpfile' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false)
            ),
            '__compiler_fread' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64)
            ),
            '__compiler_fgets' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64)
            ),
            '__compiler_fseek' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $i64, $i64)
            ),
            '__compiler_ftell' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64)
            ),
            '__compiler_stream_get_contents' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i64, $i64)
            ),
            '__compiler_flock' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64, $i64)
            ),
            '__compiler_fpassthru' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64)
            ),
            '__compiler_fgetc' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64)
            ),
            '__compiler_ftruncate' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64, $i64)
            ),
            '__compiler_fflush' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_feof' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_stream_supports' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64, $i64)
            ),
            '__phpc_try_fopen_stdio' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            ),
            '__phpc_try_fopen_php_memory' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            ),
            '__phpc_try_fopen_data_uri' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            ),
            '__phpc_php_fopen_plain' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            ),
            default => throw new \LogicException('JitStreamIoKernel: unknown '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        // Module-local stdio after LibcExtern always-on drop (#31606 / fopen family #31764).
        // Module-local close(2) after open/close/read/write always-on drop (#31817).
        // strcmp(3) after always-on LibcExtern drop (#31971).
        // strncmp(3) after leftover Module always-on drop (#32382 / #31839).
        // malloc/free after always-on LibcExtern drop (#32273).
        // __phpc_resolve_stream after always-on LibcExtern drop (#32287).
        // fileno/flock after Type always-on flock/fpassthru drop (#33122) — thin AOT libc force.
        LibcExtern::ensureStrcmpDecl($context);
        LibcExtern::ensureStrncmp($context);
        LibcExtern::ensureMallocFamily($context);
        LibcExtern::ensureResolveStreamDecl($context);
        LibcExtern::ensurePosixFd($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['__string__strlen', $i64, [$strPtr]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['fwrite', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fopen', $i8p, [$i8p, $i8p]],
            ['popen', $i8p, [$i8p, $i8p]],
            ['pclose', $i32, [$i8p]],
            ['fclose', $i32, [$i8p]],
            ['tmpfile', $i8p, []],
            ['strdup', $i8p, [$i8p]],
            ['fread', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fgets', $i8p, [$i8p, $i32, $i8p]],
            ['fseek', $i32, [$i8p, $i64, $i32]],
            ['ftell', $i64, [$i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['ferror', $i32, [$i8p]],
            ['strcmp', $i32, [$i8p, $i8p]],
            ['strchr', $i8p, [$i8p, $i32]],
            // strstr(3) for data:// ;base64 marker (#34744).
            ['strstr', $i8p, [$i8p, $i8p]],
            ['dup', $i32, [$i32]],
            ['fdopen', $i8p, [$i32, $i8p]],
            ['close', $i32, [$i32]],
            ['fileno', $i32, [$i8p]],
            ['flock', $i32, [$i32, $i32]],
            ['fgetc', $i32, [$i8p]],
            ['ungetc', $i32, [$i32, $i8p]],
            ['ftruncate', $i32, [$i32, $i64]],
            ['fflush', $i32, [$i8p]],
            ['feof', $i32, [$i8p]],
            ['__compiler_stream_filter_apply_write', $strPtr, [$i64, $strPtr]],
            ['__compiler_stream_filter_apply_read', $strPtr, [$i64, $strPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        // getNamedFunction before addFunction — lookup miss must not mint name.1 (#33832 / peer #33774 / #32122).
        LibcExtern::ensureExternalDecl($context, $name, $ft);
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ptrTableTy = $i8p->arrayType(self::MAX_HANDLES);
        $i32TableTy = $i32->arrayType(self::MAX_HANDLES);
        $wasUsedTy = $context->getTypeFromString('int8')->arrayType(self::MAX_HANDLES);

        foreach ([
            self::GLOBAL_HANDLES => $ptrTableTy,
            self::GLOBAL_PATHS => $ptrTableTy,
            self::GLOBAL_CHUNK_SIZE => $i32TableTy,
            self::GLOBAL_WRITE_BUFFER => $i32TableTy,
            self::GLOBAL_READ_BUFFER => $i32TableTy,
            self::GLOBAL_WAS_USED => $wasUsedTy,
            self::GLOBAL_IS_POPEN => $wasUsedTy,
        ] as $name => $ty) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($ty, $name);
        }
    }

    private static function loadPtrSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamIoKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storePtrSlot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamIoKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
        if (self::GLOBAL_HANDLES === $globalName && Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            StreamLibcHandleRuntime::emitRegisterHandle($context, $handle, $value);
        }
    }

    private static function loadI32Slot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamIoKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeI32Slot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamIoKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeWasUsed(Context $context, Value $handle): void
    {
        self::storeI8Flag($context, self::GLOBAL_WAS_USED, $handle);
    }

    private static function storeIsPopen(Context $context, Value $handle): void
    {
        self::storeI8Flag($context, self::GLOBAL_IS_POPEN, $handle);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            StreamLibcHandleRuntime::emitMarkPopen($context, $handle);
        }
    }

    private static function storeI8Flag(Context $context, string $globalName, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('JitStreamIoKernel: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zeroI64, $handle);
        $context->builder->store($i8->constInt(1, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function emitFwrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fwrite_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $data = $fn->getParam(1);
        $length = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $zeroI64 = $i64->constInt(0, false);
        $nullPtr = $i8p->constNull();
        $nullStr = $context->getTypeFromString('__string__*')->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $data, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle), $nullPtr)
        );
        $failBb = $fn->appendBasicBlock('fwrite_fail');
        $workBb = $fn->appendBasicBlock('fwrite_work');
        $context->builder->branchIf($badArgs, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $dataLenI64 = $context->builder->call($context->lookupFunction('__string__strlen'), $data);
        $dataLen = $context->builder->trunc($dataLenI64, $sizeT);
        $zeroSize = $sizeT->constInt(0, false);
        $zeroLenBb = $fn->appendBasicBlock('fwrite_zero');
        $doWriteBb = $fn->appendBasicBlock('fwrite_do');
        $lenNegBb = $fn->appendBasicBlock('fwrite_len_neg');
        $lenPosBb = $fn->appendBasicBlock('fwrite_len_pos');
        $lenNeg = $context->builder->icmp(Builder::INT_SLT, $length, $zeroI64);
        $context->builder->branchIf($lenNeg, $lenNegBb, $lenPosBb);

        $context->builder->positionAtEnd($lenNegBb);
        $context->builder->branch($zeroLenBb);

        $context->builder->positionAtEnd($lenPosBb);
        $lenLtData = $context->builder->icmp(Builder::INT_SLT, $length, $dataLenI64);
        $writeLen = $context->builder->select(
            $lenLtData,
            $context->builder->trunc($length, $sizeT),
            $dataLen
        );
        $isZero = $context->builder->icmp(Builder::INT_EQ, $writeLen, $zeroSize);
        $context->builder->branchIf($isZero, $zeroLenBb, $doWriteBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $context->builder->returnValue($zeroI64);

        $context->builder->positionAtEnd($doWriteBb);
        $filtered = $context->builder->call(
            $context->lookupFunction('__compiler_stream_filter_apply_write'),
            $handle,
            $data
        );
        $n = $context->builder->call(
            $context->lookupFunction('fwrite'),
            self::stringData($context, $filtered),
            $sizeT->constInt(1, false),
            $writeLen,
            $fp
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $n, $writeLen);
        $retBb = $fn->appendBasicBlock('fwrite_ret');
        $context->builder->branchIf($ok, $retBb, $failBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($context->builder->sext($n, $i64));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFopen(Context $context, LlvmFunction $fn): void
    {
        self::emitOpenHandle($context, $fn, withPath: true);
    }

    /**
     * __phpc_php_fopen_plain(path, mode) — libc fopen for r/w/a; open(2)+fdopen for PHP c/x (#33433).
     *
     * php-src: main/streams/plain_wrapper.c — php_stream_parse_fopen_modes
     * (`c` → O_CREAT, `x` → O_CREAT|O_EXCL; glibc fopen rejects both with EINVAL).
     */
    private static function emitPhpFopenPlain(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('php_fopen_plain_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);
        // Linux fcntl.h — O_WRONLY=1, O_RDWR=2, O_CREAT=0100, O_EXCL=0200
        $oWronly = $i32->constInt(1, false);
        $oRdwr = $i32->constInt(2, false);
        $oCreat = $i32->constInt(64, false);
        $oExcl = $i32->constInt(128, false);
        $mode0666 = $i32->constInt(0666, false);

        $failBb = $fn->appendBasicBlock('php_fopen_plain_fail');
        $libcBb = $fn->appendBasicBlock('php_fopen_plain_libc');
        $specialBb = $fn->appendBasicBlock('php_fopen_plain_cx');

        $base = $context->builder->load($mode);
        $isC = $context->builder->icmp(Builder::INT_EQ, $base, $i8->constInt(\ord('c'), false));
        $isX = $context->builder->icmp(Builder::INT_EQ, $base, $i8->constInt(\ord('x'), false));
        $context->builder->branchIf(
            $context->builder->or($isC, $isX),
            $specialBb,
            $libcBb
        );

        $context->builder->positionAtEnd($libcBb);
        $libcFp = $context->builder->call($context->lookupFunction('fopen'), $path, $mode);
        $context->builder->returnValue($libcFp);

        $context->builder->positionAtEnd($specialBb);
        $plusPtr = $context->builder->call(
            $context->lookupFunction('strchr'),
            $mode,
            $i32->constInt(\ord('+'), false)
        );
        $hasPlus = $context->builder->icmp(Builder::INT_NE, $plusPtr, $nullPtr);
        $flagsCreat = $context->builder->select(
            $isX,
            $context->builder->or($oCreat, $oExcl),
            $oCreat
        );
        $flags = $context->builder->select(
            $hasPlus,
            $context->builder->or($flagsCreat, $oRdwr),
            $context->builder->or($flagsCreat, $oWronly)
        );
        $fdopenMode = $context->builder->select(
            $hasPlus,
            self::literalCstr($context, 'r+b'),
            self::literalCstr($context, 'wb')
        );
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $path,
            $flags,
            $mode0666
        );
        $openOkBb = $fn->appendBasicBlock('php_fopen_plain_open_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $fd, $zero),
            $failBb,
            $openOkBb
        );

        $context->builder->positionAtEnd($openOkBb);
        $fp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            $fd,
            $fdopenMode
        );
        $fdopenOkBb = $fn->appendBasicBlock('php_fopen_plain_fdopen_ok');
        $fdopenFailBb = $fn->appendBasicBlock('php_fopen_plain_fdopen_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $fdopenFailBb,
            $fdopenOkBb
        );

        $context->builder->positionAtEnd($fdopenFailBb);
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($fdopenOkBb);
        $context->builder->returnValue($fp);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullPtr);
    }

    /**
     * __phpc_try_fopen_stdio(path, mode) — fdopen dup of fd 0/1/2 for php://stdin|stdout|stderr (#4648).
     */
    private static function emitTryFopenStdio(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stdio_try_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);

        $missBb = $fn->appendBasicBlock('stdio_try_miss');
        $failBb = $fn->appendBasicBlock('stdio_try_fail');

        /** @var list<array{uri: string, fd: int}> $stdio */
        $stdio = [
            ['uri' => 'php://stdin', 'fd' => 0],
            ['uri' => 'php://stdout', 'fd' => 1],
            ['uri' => 'php://stderr', 'fd' => 2],
            ['uri' => 'php://output', 'fd' => 1],
        ];
        $nextBb = $missBb;
        foreach (array_reverse($stdio) as $entryDef) {
            $checkBb = $fn->appendBasicBlock('stdio_try_check_'.$entryDef['fd']);
            $matchBb = $fn->appendBasicBlock('stdio_try_match_'.$entryDef['fd']);
            $context->builder->positionAtEnd($checkBb);
            $cmp = $context->builder->call(
                $context->lookupFunction('strcmp'),
                $path,
                self::literalCstr($context, $entryDef['uri'])
            );
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            $context->builder->branchIf($isMatch, $matchBb, $nextBb);
            $context->builder->positionAtEnd($matchBb);
            $fp = self::fdopenDupStdio($context, $fn, $i32->constInt($entryDef['fd'], false), $mode, $failBb);
            $context->builder->returnValue($fp);
            $nextBb = $checkBb;
        }

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($nullPtr);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullPtr);
    }

    /**
     * __phpc_try_fopen_php_memory(path, mode) — tmpfile() for php://memory|temp (#10487, ext/standard/streams.c).
     */
    private static function emitTryFopenPhpMemory(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('php_mem_try_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);

        $missBb = $fn->appendBasicBlock('php_mem_try_miss');
        $tempCheckBb = $fn->appendBasicBlock('php_mem_try_temp_check');
        $openBb = $fn->appendBasicBlock('php_mem_try_open');
        $failBb = $fn->appendBasicBlock('php_mem_try_fail');
        $okBb = $fn->appendBasicBlock('php_mem_try_ok');

        $isMemory = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $path,
            self::literalCstr($context, 'php://memory')
        );
        $memoryMatch = $context->builder->icmp(Builder::INT_EQ, $isMemory, $zero);
        $context->builder->branchIf($memoryMatch, $openBb, $tempCheckBb);

        $context->builder->positionAtEnd($tempCheckBb);
        $isTemp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'php://temp'),
            $sizeT->constInt(10, false)
        );
        $tempMatch = $context->builder->icmp(Builder::INT_EQ, $isTemp, $zero);
        $context->builder->branchIf($tempMatch, $openBb, $missBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call($context->lookupFunction('tmpfile'));
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($fp);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($nullPtr);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullPtr);
    }

    /**
     * __phpc_try_fopen_data_uri(path, mode) — RFC2397 data:// as tmpfile + payload (#34744).
     *
     * php-src: ext/standard/php_data_wrapper.c — php_stream_data_wrapper (read-only).
     * Peer: VmDataStream::open / FileGetContentsJitHelper::decodeDataUri (#34731).
     * Shape matches php://memory (tmpfile) so existing libc fread/stream_get_contents work.
     */
    private static function emitTryFopenDataUri(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('data_uri_try_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullPtr = $i8p->constNull();
        $nullStr = $strPtr->constNull();
        $zero = $i32->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $missBb = $fn->appendBasicBlock('data_uri_try_miss');
        $modeOkBb = $fn->appendBasicBlock('data_uri_try_mode_ok');
        $prefixOkBb = $fn->appendBasicBlock('data_uri_try_prefix_ok');
        $commaOkBb = $fn->appendBasicBlock('data_uri_try_comma_ok');
        $b64CheckBb = $fn->appendBasicBlock('data_uri_try_b64_check');
        $plainBb = $fn->appendBasicBlock('data_uri_try_plain');
        $b64Bb = $fn->appendBasicBlock('data_uri_try_b64');
        $openBb = $fn->appendBasicBlock('data_uri_try_open');
        $writeBb = $fn->appendBasicBlock('data_uri_try_write');
        $rewindBb = $fn->appendBasicBlock('data_uri_try_rewind');
        $failBb = $fn->appendBasicBlock('data_uri_try_fail');
        $failCloseBb = $fn->appendBasicBlock('data_uri_try_fail_close');

        // Zend opens data:// for any non-empty fopen mode (stream not writable — php_data_wrapper.c).
        $mode0 = $context->builder->load($mode);
        $modeEmpty = $context->builder->icmp(Builder::INT_EQ, $mode0, $i8->constInt(0, false));
        $context->builder->branchIf($modeEmpty, $missBb, $modeOkBb);

        $context->builder->positionAtEnd($modeOkBb);
        $prefixCmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'data:'),
            $sizeT->constInt(5, false)
        );
        $isData = $context->builder->icmp(Builder::INT_EQ, $prefixCmp, $zero);
        $context->builder->branchIf($isData, $prefixOkBb, $missBb);

        $context->builder->positionAtEnd($prefixOkBb);
        $comma = $context->builder->call(
            $context->lookupFunction('strchr'),
            $path,
            $i32->constInt(\ord(','), false)
        );
        $hasComma = $context->builder->icmp(Builder::INT_NE, $comma, $nullPtr);
        $context->builder->branchIf($hasComma, $commaOkBb, $missBb);

        $context->builder->positionAtEnd($commaOkBb);
        $payload = $context->builder->gep($comma, $i64->constInt(1, false));
        $context->builder->branch($b64CheckBb);

        $context->builder->positionAtEnd($b64CheckBb);
        // NestedJIT-safe marker match (#34731 uses stripos ';base64,'); cover common casings.
        $b64Needle = $context->builder->call(
            $context->lookupFunction('strstr'),
            $path,
            self::literalCstr($context, ';base64')
        );
        $b64Needle2 = $context->builder->call(
            $context->lookupFunction('strstr'),
            $path,
            self::literalCstr($context, ';Base64')
        );
        $b64Needle3 = $context->builder->call(
            $context->lookupFunction('strstr'),
            $path,
            self::literalCstr($context, ';BASE64')
        );
        $isB64 = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $b64Needle, $nullPtr),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_NE, $b64Needle2, $nullPtr),
                $context->builder->icmp(Builder::INT_NE, $b64Needle3, $nullPtr)
            )
        );
        $context->builder->branchIf($isB64, $b64Bb, $plainBb);

        $context->builder->positionAtEnd($plainBb);
        $plainLen = $context->builder->call($context->lookupFunction('strlen'), $payload);
        $plainLenI64 = $context->builder->zExt($plainLen, $i64);
        $plainTail = $context->builder->getInsertBlock();
        $context->builder->branch($openBb);

        $context->builder->positionAtEnd($b64Bb);
        $rawLen = $context->builder->call($context->lookupFunction('strlen'), $payload);
        $rawLenI64 = $context->builder->zExt($rawLen, $i64);
        $rawStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $rawLenI64,
            $payload
        );
        $decoded = $context->builder->call(
            $context->lookupFunction('__compiler_base64_decode'),
            $rawStr
        );
        $decodedOkBb = $fn->appendBasicBlock('data_uri_try_b64_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $decoded, $nullStr),
            $missBb,
            $decodedOkBb
        );
        $context->builder->positionAtEnd($decodedOkBb);
        $b64Ptr = self::stringData($context, $decoded);
        $b64LenI64 = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $decoded
        );
        $b64Tail = $context->builder->getInsertBlock();
        $context->builder->branch($openBb);

        $context->builder->positionAtEnd($openBb);
        $dataPtrPhi = $context->builder->phi($i8p, 'data_uri_ptr');
        $dataPtrPhi->addIncoming($payload, $plainTail);
        $dataPtrPhi->addIncoming($b64Ptr, $b64Tail);
        $dataLenPhi = $context->builder->phi($i64, 'data_uri_len');
        $dataLenPhi->addIncoming($plainLenI64, $plainTail);
        $dataLenPhi->addIncoming($b64LenI64, $b64Tail);

        $fp = $context->builder->call($context->lookupFunction('tmpfile'));
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $writeBb);

        $context->builder->positionAtEnd($writeBb);
        $lenZero = $context->builder->icmp(Builder::INT_EQ, $dataLenPhi, $zeroI64);
        $doWriteBb = $fn->appendBasicBlock('data_uri_try_do_write');
        $context->builder->branchIf($lenZero, $rewindBb, $doWriteBb);

        $context->builder->positionAtEnd($doWriteBb);
        $wrote = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $dataPtrPhi,
            $oneSize,
            $context->builder->trunc($dataLenPhi, $sizeT),
            $fp
        );
        $wroteI64 = $context->builder->zExt($wrote, $i64);
        $short = $context->builder->icmp(Builder::INT_NE, $wroteI64, $dataLenPhi);
        $context->builder->branchIf($short, $failCloseBb, $rewindBb);

        $context->builder->positionAtEnd($rewindBb);
        $seekRc = $context->builder->call(
            $context->lookupFunction('fseek'),
            $fp,
            $zeroI64,
            $zero
        );
        $seekFail = $context->builder->icmp(Builder::INT_NE, $seekRc, $zero);
        $okBb = $fn->appendBasicBlock('data_uri_try_ok');
        $context->builder->branchIf($seekFail, $failCloseBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($fp);

        $context->builder->positionAtEnd($failCloseBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($nullPtr);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullPtr);
    }

    private static function fdopenDupStdio(
        Context $context,
        LlvmFunction $fn,
        Value $fd,
        Value $mode,
        BasicBlock $failReturnBb
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();

        $dupFd = $context->builder->call($context->lookupFunction('dup'), $fd);
        $dupFail = $context->builder->icmp(Builder::INT_SLT, $dupFd, $i32->constInt(0, false));
        $openBb = $fn->appendBasicBlock('stdio_fdopen');
        $context->builder->branchIf($dupFail, $failReturnBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            $dupFd,
            $mode
        );
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $closeDupBb = $fn->appendBasicBlock('stdio_fdopen_close_dup');
        $okBb = $fn->appendBasicBlock('stdio_fdopen_ok');
        $context->builder->branchIf($fpNull, $closeDupBb, $okBb);

        $context->builder->positionAtEnd($closeDupBb);
        $context->builder->call($context->lookupFunction('close'), $dupFd);
        $context->builder->branch($failReturnBb);

        $context->builder->positionAtEnd($okBb);

        return $fp;
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function emitPopen(Context $context, LlvmFunction $fn): void
    {
        $prefix = 'popen';
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $command = $fn->getParam(0);
        $mode = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $defaultBuf = $i32->constInt(self::DEFAULT_BUFFER_SIZE, false);

        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $openBb = $fn->appendBasicBlock($prefix.'_call');

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $command, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $mode, $nullStr)
        );
        $context->builder->branchIf($badArgs, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = $context->builder->call(
            $context->lookupFunction('popen'),
            self::stringData($context, $command),
            self::stringData($context, $mode)
        );

        $loopInitBb = $fn->appendBasicBlock($prefix.'_loop_init');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $loopInitBb);

        $loopCheckBb = $fn->appendBasicBlock($prefix.'_loop_check');
        $loopBodyBb = $fn->appendBasicBlock($prefix.'_loop_body');
        $loopSkipBb = $fn->appendBasicBlock($prefix.'_loop_skip');
        $loopIncBb = $fn->appendBasicBlock($prefix.'_loop_inc');
        $exhaustBb = $fn->appendBasicBlock($prefix.'_exhaust');

        $context->builder->positionAtEnd($loopInitBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $idPhi = $context->builder->phi($i64, $prefix.'_id');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $exhaustBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $slotFp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock($prefix.'_alloc');
        $context->builder->branchIf($slotFree, $allocBb, $loopSkipBb);

        $context->builder->positionAtEnd($allocBb);
        self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $fp);
        self::storeI32Slot($context, self::GLOBAL_CHUNK_SIZE, $idPhi, $defaultChunk);
        self::storeI32Slot($context, self::GLOBAL_WRITE_BUFFER, $idPhi, $defaultBuf);
        self::storeI32Slot($context, self::GLOBAL_READ_BUFFER, $idPhi, $defaultBuf);
        self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $nullPtr);
        self::storeWasUsed($context, $idPhi);
        self::storeIsPopen($context, $idPhi);
        $context->builder->returnValue($idPhi);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($exhaustBb);
        $context->builder->call($context->lookupFunction('pclose'), $fp);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitTmpfile(Context $context, LlvmFunction $fn): void
    {
        self::emitOpenHandle($context, $fn, withPath: false);
    }

    private static function emitOpenHandle(Context $context, LlvmFunction $fn, bool $withPath): void
    {
        $prefix = $withPath ? 'fopen' : 'tmpfile';
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $path = $withPath ? $fn->getParam(0) : null;
        $mode = $withPath ? $fn->getParam(1) : null;
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK_SIZE, false);
        $defaultBuf = $i32->constInt(self::DEFAULT_BUFFER_SIZE, false);

        $failBb = $fn->appendBasicBlock($prefix.'_fail');
        $openBb = $fn->appendBasicBlock($prefix.'_call');

        if ($withPath) {
            $badArgs = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $path, $nullStr),
                $context->builder->icmp(Builder::INT_EQ, $mode, $nullStr)
            );
            $context->builder->branchIf($badArgs, $failBb, $openBb);

            $context->builder->positionAtEnd($openBb);
            $stdioFp = $context->builder->call(
                $context->lookupFunction('__phpc_try_fopen_stdio'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
            $phpMemBb = $fn->appendBasicBlock($prefix.'_php_mem');
            $plainBb = $fn->appendBasicBlock($prefix.'_plain');
            $mergeBb = $fn->appendBasicBlock($prefix.'_fp_merge');
            $stdioNull = $context->builder->icmp(Builder::INT_EQ, $stdioFp, $nullPtr);
            $context->builder->branchIf($stdioNull, $phpMemBb, $mergeBb);

            $context->builder->positionAtEnd($phpMemBb);
            $phpMemFp = $context->builder->call(
                $context->lookupFunction('__phpc_try_fopen_php_memory'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
            $phpMemOkBb = $fn->appendBasicBlock($prefix.'_php_mem_ok');
            $dataUriBb = $fn->appendBasicBlock($prefix.'_data_uri');
            $phpMemNull = $context->builder->icmp(Builder::INT_EQ, $phpMemFp, $nullPtr);
            $context->builder->branchIf($phpMemNull, $dataUriBb, $phpMemOkBb);

            $context->builder->positionAtEnd($phpMemOkBb);
            $phpMemTail = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($dataUriBb);
            $dataUriFp = $context->builder->call(
                $context->lookupFunction('__phpc_try_fopen_data_uri'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
            $dataUriOkBb = $fn->appendBasicBlock($prefix.'_data_uri_ok');
            $dataUriNull = $context->builder->icmp(Builder::INT_EQ, $dataUriFp, $nullPtr);
            $context->builder->branchIf($dataUriNull, $plainBb, $dataUriOkBb);

            $context->builder->positionAtEnd($dataUriOkBb);
            $dataUriTail = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($plainBb);
            // PHP modes c/x are not valid libc fopen(3) strings — open(2)+fdopen (#33433).
            $plainFp = $context->builder->call(
                $context->lookupFunction('__phpc_php_fopen_plain'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
            $plainTail = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($mergeBb);
            $fpPhi = $context->builder->phi($i8p, $prefix.'_fp');
            $fpPhi->addIncoming($stdioFp, $openBb);
            $fpPhi->addIncoming($phpMemFp, $phpMemOkBb);
            $fpPhi->addIncoming($dataUriFp, $dataUriOkBb);
            $fpPhi->addIncoming($plainFp, $plainTail);
            $fp = $fpPhi;
        } else {
            $context->builder->branch($openBb);

            $context->builder->positionAtEnd($openBb);
            $fp = $context->builder->call($context->lookupFunction('tmpfile'));
        }

        $loopInitBb = $fn->appendBasicBlock($prefix.'_loop_init');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $context->builder->branchIf($fpNull, $failBb, $loopInitBb);

        $loopCheckBb = $fn->appendBasicBlock($prefix.'_loop_check');
        $loopBodyBb = $fn->appendBasicBlock($prefix.'_loop_body');
        $loopSkipBb = $fn->appendBasicBlock($prefix.'_loop_skip');
        $loopIncBb = $fn->appendBasicBlock($prefix.'_loop_inc');
        $exhaustBb = $fn->appendBasicBlock($prefix.'_exhaust');

        $context->builder->positionAtEnd($loopInitBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($loopCheckBb);
        $idPhi = $context->builder->phi($i64, $prefix.'_id');
        $idPhi->addIncoming($i64->constInt(3, false), $loopInitBb);
        $maxId = $i64->constInt(self::MAX_HANDLES, false);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $maxId);
        $context->builder->branchIf($atEnd, $exhaustBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $slotFp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock($prefix.'_alloc');
        $context->builder->branchIf($slotFree, $allocBb, $loopSkipBb);

        $context->builder->positionAtEnd($allocBb);
        self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $fp);
        self::storeI32Slot($context, self::GLOBAL_CHUNK_SIZE, $idPhi, $defaultChunk);
        self::storeI32Slot($context, self::GLOBAL_WRITE_BUFFER, $idPhi, $defaultBuf);
        self::storeI32Slot($context, self::GLOBAL_READ_BUFFER, $idPhi, $defaultBuf);

        if ($withPath) {
            $dupBb = $fn->appendBasicBlock($prefix.'_dup');
            $dupFailBb = $fn->appendBasicBlock($prefix.'_dup_fail');
            $doneBb = $fn->appendBasicBlock($prefix.'_done');
            $context->builder->branch($dupBb);

            $context->builder->positionAtEnd($dupBb);
            $dup = $context->builder->call($context->lookupFunction('strdup'), self::stringData($context, $path));
            $dupNull = $context->builder->icmp(Builder::INT_EQ, $dup, $nullPtr);
            $context->builder->branchIf($dupNull, $dupFailBb, $doneBb);

            $context->builder->positionAtEnd($dupFailBb);
            self::storePtrSlot($context, self::GLOBAL_HANDLES, $idPhi, $nullPtr);
            $context->builder->call($context->lookupFunction('fclose'), $fp);
            $context->builder->returnValue($minusOne);

            $context->builder->positionAtEnd($doneBb);
            // GLOBAL_PATHS is the user-script SSOT (avoid nested StreamPathRuntime / #16075).
            self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $dup);
        } else {
            self::storePtrSlot($context, self::GLOBAL_PATHS, $idPhi, $nullPtr);
        }

        self::storeWasUsed($context, $idPhi);
        $context->builder->returnValue($idPhi);

        $context->builder->positionAtEnd($loopSkipBb);
        $context->builder->branch($loopIncBb);

        $context->builder->positionAtEnd($loopIncBb);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopIncBb);
        $context->builder->branch($loopCheckBb);

        $context->builder->positionAtEnd($exhaustBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitFread(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fread_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $emptyCstr = $context->pointerFromStringConstant('');

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zeroI64);
        $failBb = $fn->appendBasicBlock('fread_fail');
        $checkFpBb = $fn->appendBasicBlock('fread_check_fp');
        $context->builder->branchIf($negLen, $failBb, $checkFpBb);

        $context->builder->positionAtEnd($checkFpBb);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $zeroLenBb = $fn->appendBasicBlock('fread_zero');
        $allocBb = $fn->appendBasicBlock('fread_alloc');
        $context->builder->branchIf($fpNull, $failBb, $zeroLenBb);

        $context->builder->positionAtEnd($zeroLenBb);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $length, $zeroI64);
        $emptyBb = $fn->appendBasicBlock('fread_empty');
        $context->builder->branchIf($isZero, $emptyBb, $allocBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $emptyCstr
        );
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $emptyStr
            )
        );

        $context->builder->positionAtEnd($allocBb);
        $readLen = $context->builder->trunc($length, $sizeT);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $readLen);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('fread_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $got = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $readLen,
            $fp
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $errBb = $fn->appendBasicBlock('fread_err_check');
        $makeBb = $fn->appendBasicBlock('fread_make');
        $context->builder->branchIf($gotZero, $errBb, $makeBb);

        $context->builder->positionAtEnd($errBb);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('ferror'), $fp), $zeroI32);
        $freeFailBb = $fn->appendBasicBlock('fread_free_fail');
        $context->builder->branchIf($hasErr, $freeFailBb, $makeBb);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($makeBb);
        $gotI64 = $context->builder->sext($got, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $gotI64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $result
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    /**
     * Idempotent libc fgets for thin AOT (#27663). Do not recreate after NestedJIT.
     */
    public static function implementFgetsForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fgets');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fgets_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fgets', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_fgets');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_fgets');
        }
        self::emitFgets($context, $fn);
        $context->registerFunction('__compiler_fgets', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** libc fgets on FILE* handle table — length -1 → {@see DEFAULT_BUFFER_SIZE} (#27663). */
    private static function emitFgets(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fgets_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $defaultLen = $i64->constInt(self::DEFAULT_BUFFER_SIZE, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $failBb = $fn->appendBasicBlock('fgets_fail');
        $lenBb = $fn->appendBasicBlock('fgets_len');
        $context->builder->branchIf($fpNull, $failBb, $lenBb);

        $context->builder->positionAtEnd($lenBb);
        $isOmit = $context->builder->icmp(Builder::INT_EQ, $length, $i64->constInt(-1, true));
        $positive = $context->builder->icmp(Builder::INT_SGT, $length, $zeroI64);
        $okLen = $context->builder->or($isOmit, $positive);
        $allocBb = $fn->appendBasicBlock('fgets_alloc');
        $context->builder->branchIf($okLen, $allocBb, $failBb);

        $context->builder->positionAtEnd($allocBb);
        $bufLen = $context->builder->select($isOmit, $defaultLen, $length);
        $allocSize = $context->builder->trunc($bufLen, $sizeT);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $allocSize);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('fgets_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $sizeI32 = $context->builder->trunc($bufLen, $i32);
        $got = $context->builder->call(
            $context->lookupFunction('fgets'),
            $buf,
            $sizeI32,
            $fp
        );
        $gotNull = $context->builder->icmp(Builder::INT_EQ, $got, $nullPtr);
        $freeFailBb = $fn->appendBasicBlock('fgets_free_fail');
        $makeBb = $fn->appendBasicBlock('fgets_make');
        $context->builder->branchIf($gotNull, $freeFailBb, $makeBb);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($makeBb);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $gotLen = $context->builder->call($context->lookupFunction('strlen'), $buf);
        $gotLenI64 = $context->builder->zExt($gotLen, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $gotLenI64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        // php://memory|temp: Zend sets feof after the last successful fgets; libc does not
        // until a failed read. Peek only for those URIs — regular files keep feof=0 after
        // the last line (Zend measured; #33555 / #33319).
        $path = self::loadPtrSlot($context, self::GLOBAL_PATHS, $handle);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $memCheckBb = $fn->appendBasicBlock('fgets_mem_check');
        $afterPeekBb = $fn->appendBasicBlock('fgets_after_peek');
        $context->builder->branchIf($pathNull, $afterPeekBb, $memCheckBb);

        $context->builder->positionAtEnd($memCheckBb);
        $sizeT = $context->getTypeFromString('size_t');
        $cmpMem = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'php://memory'),
            $sizeT->constInt(12, false)
        );
        $isMem = $context->builder->icmp(Builder::INT_EQ, $cmpMem, $i32->constInt(0, false));
        $tempCheckBb = $fn->appendBasicBlock('fgets_temp_check');
        $peekBb = $fn->appendBasicBlock('fgets_peek');
        $context->builder->branchIf($isMem, $peekBb, $tempCheckBb);

        $context->builder->positionAtEnd($tempCheckBb);
        $cmpTemp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'php://temp'),
            $sizeT->constInt(10, false)
        );
        $isTemp = $context->builder->icmp(Builder::INT_EQ, $cmpTemp, $i32->constInt(0, false));
        $context->builder->branchIf($isTemp, $peekBb, $afterPeekBb);

        $context->builder->positionAtEnd($peekBb);
        $peek = $context->builder->call($context->lookupFunction('fgetc'), $fp);
        $minusOne = $i32->constInt(-1, true);
        $atEof = $context->builder->icmp(Builder::INT_EQ, $peek, $minusOne);
        $keepBb = $fn->appendBasicBlock('fgets_peek_keep');
        $ungetBb = $fn->appendBasicBlock('fgets_peek_unget');
        $context->builder->branchIf($atEof, $keepBb, $ungetBb);

        $context->builder->positionAtEnd($keepBb);
        $context->builder->branch($afterPeekBb);

        $context->builder->positionAtEnd($ungetBb);
        $context->builder->call($context->lookupFunction('ungetc'), $peek, $fp);
        $context->builder->branch($afterPeekBb);

        $context->builder->positionAtEnd($afterPeekBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $result
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }



    public static function implementFseekForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fseek');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fseek_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fseek', $probe);
                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_fseek');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_fseek');
        }
        self::emitFseek($context, $fn);
        $context->registerFunction('__compiler_fseek', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitFseek(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fseek_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $offset = $fn->getParam(1);
        $whence = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $minusOne = $i64->constInt(-1, true);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('fseek_fail');
        $okBb = $fn->appendBasicBlock('fseek_ok');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr), $failBb, $okBb);
        $context->builder->positionAtEnd($okBb);
        $rc = $context->builder->call($context->lookupFunction('fseek'), $fp, $offset, $context->builder->trunc($whence, $i32));
        $context->builder->returnValue($context->builder->sext($rc, $i64));
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    public static function implementFtellForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftell');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('ftell_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_ftell', $probe);
                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_ftell');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_ftell');
        }
        self::emitFtell($context, $fn);
        $context->registerFunction('__compiler_ftell', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitFtell(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ftell_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $minusOne = $i64->constInt(-1, true);
        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('ftell_fail');
        $okBb = $fn->appendBasicBlock('ftell_ok');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr), $failBb, $okBb);
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('ftell'), $fp));
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    /**
     * Idempotent libc stream_get_contents for thin AOT (#27437).
     *
     * NestedJIT StreamReadJitHelper→VmFs cannot see JitStreamIoKernel's FILE* table
     * (php://memory opens via tmpfile()). Replace the bridge after NestedJIT.
     * php-src: ext/standard/file.c — PHP_FUNCTION(stream_get_contents)
     */
    public static function implementStreamGetContentsForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_stream_get_contents');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('sgc_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_stream_get_contents', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_stream_get_contents');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_stream_get_contents');
        }
        self::emitStreamGetContents($context, $fn);
        $context->registerFunction('__compiler_stream_get_contents', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * libc FILE* stream_get_contents — seekable tmpfile/memory via remaining-size fread (#27437).
     *
     * offset >= 0 → fseek SEEK_SET (fail → null/false); maxlength 0 → ""; maxlength < 0 → to EOF;
     * maxlength > 0 → up to that many bytes. Empty remaining is "" (not false), matching php-src.
     */
    private static function emitStreamGetContents(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sgc_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $maxlength = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $emptyCstr = $context->pointerFromStringConstant('');

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('sgc_fail');
        $seekCheckBb = $fn->appendBasicBlock('sgc_seek_check');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $seekCheckBb
        );

        // php-src file.c: only offset >= 0 seeks; negative keeps current position (#23190).
        $context->builder->positionAtEnd($seekCheckBb);
        $needSeek = $context->builder->icmp(Builder::INT_SGE, $offset, $zeroI64);
        $doSeekBb = $fn->appendBasicBlock('sgc_do_seek');
        $lenBb = $fn->appendBasicBlock('sgc_len');
        $context->builder->branchIf($needSeek, $doSeekBb, $lenBb);

        $context->builder->positionAtEnd($doSeekBb);
        $seekRc = $context->builder->call(
            $context->lookupFunction('fseek'),
            $fp,
            $offset,
            $i32->constInt(0, false) // SEEK_SET
        );
        $seekFail = $context->builder->icmp(Builder::INT_NE, $seekRc, $zeroI32);
        $context->builder->branchIf($seekFail, $failBb, $lenBb);

        $context->builder->positionAtEnd($lenBb);
        $isZeroLen = $context->builder->icmp(Builder::INT_EQ, $maxlength, $zeroI64);
        $emptyBb = $fn->appendBasicBlock('sgc_empty');
        $sizeBb = $fn->appendBasicBlock('sgc_size');
        $context->builder->branchIf($isZeroLen, $emptyBb, $sizeBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $emptyCstr
        );
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $emptyStr
            )
        );

        // Seekable remaining: ftell → SEEK_END → restore → min(remaining, maxlength when > 0).
        // Non-seekable (popen pipes): ftell/SEEK_END fail — fall back to bounded fread (#33430).
        $context->builder->positionAtEnd($sizeBb);
        $pos = $context->builder->call($context->lookupFunction('ftell'), $fp);
        $posBad = $context->builder->icmp(Builder::INT_SLT, $pos, $zeroI64);
        $endSeekBb = $fn->appendBasicBlock('sgc_end_seek');
        $pipeBb = $fn->appendBasicBlock('sgc_pipe_read');
        $context->builder->branchIf($posBad, $pipeBb, $endSeekBb);

        $context->builder->positionAtEnd($endSeekBb);
        $endSeekRc = $context->builder->call(
            $context->lookupFunction('fseek'),
            $fp,
            $zeroI64,
            $i32->constInt(2, false) // SEEK_END
        );
        $endSeekFail = $context->builder->icmp(Builder::INT_NE, $endSeekRc, $zeroI32);
        $endTellBb = $fn->appendBasicBlock('sgc_end_tell');
        $context->builder->branchIf($endSeekFail, $pipeBb, $endTellBb);

        $context->builder->positionAtEnd($endTellBb);
        $end = $context->builder->call($context->lookupFunction('ftell'), $fp);
        $restoreRc = $context->builder->call(
            $context->lookupFunction('fseek'),
            $fp,
            $pos,
            $i32->constInt(0, false)
        );
        $restoreFail = $context->builder->icmp(Builder::INT_NE, $restoreRc, $zeroI32);
        $remainBb = $fn->appendBasicBlock('sgc_remain');
        $context->builder->branchIf($restoreFail, $failBb, $remainBb);

        $context->builder->positionAtEnd($remainBb);
        $remaining = $context->builder->sub($end, $pos);
        $noRemain = $context->builder->icmp(Builder::INT_SLE, $remaining, $zeroI64);
        $capBb = $fn->appendBasicBlock('sgc_cap');
        $context->builder->branchIf($noRemain, $emptyBb, $capBb);

        $context->builder->positionAtEnd($capBb);
        // maxlength < 0 → read all remaining; else min(remaining, maxlength).
        $unlimited = $context->builder->icmp(Builder::INT_SLT, $maxlength, $zeroI64);
        $capped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $maxlength, $remaining),
            $maxlength,
            $remaining
        );
        $toRead = $context->builder->select($unlimited, $remaining, $capped);
        $stillZero = $context->builder->icmp(Builder::INT_EQ, $toRead, $zeroI64);
        $allocBb = $fn->appendBasicBlock('sgc_alloc');
        $context->builder->branchIf($stillZero, $emptyBb, $allocBb);

        $context->builder->positionAtEnd($allocBb);
        $readLen = $context->builder->trunc($toRead, $sizeT);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $readLen);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $readBb = $fn->appendBasicBlock('sgc_read');
        $context->builder->branchIf($bufNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $got = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $readLen,
            $fp
        );
        $gotZero = $context->builder->icmp(Builder::INT_EQ, $got, $sizeT->constInt(0, false));
        $errBb = $fn->appendBasicBlock('sgc_err_check');
        $makeBb = $fn->appendBasicBlock('sgc_make');
        $context->builder->branchIf($gotZero, $errBb, $makeBb);

        $context->builder->positionAtEnd($errBb);
        $hasErr = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ferror'), $fp),
            $zeroI32
        );
        $freeFailBb = $fn->appendBasicBlock('sgc_free_fail');
        $freeEmptyBb = $fn->appendBasicBlock('sgc_free_empty');
        // Zero bytes + no error → empty success (EOF); error → false.
        $context->builder->branchIf($hasErr, $freeFailBb, $freeEmptyBb);

        $context->builder->positionAtEnd($freeEmptyBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($emptyBb);

        $context->builder->positionAtEnd($freeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($makeBb);
        $gotI64 = $context->builder->zExt($got, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $gotI64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $result
            )
        );

        // popen / non-seekable: single fread with maxlength or 64KiB default (#33430).
        $context->builder->positionAtEnd($pipeBb);
        $defaultPipe = $i64->constInt(65536, false);
        $pipeUnlimited = $context->builder->icmp(Builder::INT_SLT, $maxlength, $zeroI64);
        $pipeLen = $context->builder->select($pipeUnlimited, $defaultPipe, $maxlength);
        $pipeZero = $context->builder->icmp(Builder::INT_EQ, $pipeLen, $zeroI64);
        $pipeAllocBb = $fn->appendBasicBlock('sgc_pipe_alloc');
        $context->builder->branchIf($pipeZero, $emptyBb, $pipeAllocBb);

        $context->builder->positionAtEnd($pipeAllocBb);
        $pipeReadLen = $context->builder->trunc($pipeLen, $sizeT);
        $pipeBuf = $context->builder->call($context->lookupFunction('malloc'), $pipeReadLen);
        $pipeBufNull = $context->builder->icmp(Builder::INT_EQ, $pipeBuf, $nullPtr);
        $pipeReadBb = $fn->appendBasicBlock('sgc_pipe_do_read');
        $context->builder->branchIf($pipeBufNull, $failBb, $pipeReadBb);

        $context->builder->positionAtEnd($pipeReadBb);
        $pipeGot = $context->builder->call(
            $context->lookupFunction('fread'),
            $pipeBuf,
            $sizeT->constInt(1, false),
            $pipeReadLen,
            $fp
        );
        $pipeGotZero = $context->builder->icmp(Builder::INT_EQ, $pipeGot, $sizeT->constInt(0, false));
        $pipeErrBb = $fn->appendBasicBlock('sgc_pipe_err');
        $pipeMakeBb = $fn->appendBasicBlock('sgc_pipe_make');
        $context->builder->branchIf($pipeGotZero, $pipeErrBb, $pipeMakeBb);

        $context->builder->positionAtEnd($pipeErrBb);
        $pipeHasErr = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('ferror'), $fp),
            $zeroI32
        );
        $pipeFreeFail = $fn->appendBasicBlock('sgc_pipe_free_fail');
        $pipeFreeEmpty = $fn->appendBasicBlock('sgc_pipe_free_empty');
        $context->builder->branchIf($pipeHasErr, $pipeFreeFail, $pipeFreeEmpty);

        $context->builder->positionAtEnd($pipeFreeEmpty);
        $context->builder->call($context->lookupFunction('free'), $pipeBuf);
        $context->builder->branch($emptyBb);

        $context->builder->positionAtEnd($pipeFreeFail);
        $context->builder->call($context->lookupFunction('free'), $pipeBuf);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($pipeMakeBb);
        $pipeGotI64 = $context->builder->zExt($pipeGot, $i64);
        $pipeResult = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $pipeGotI64,
            $pipeBuf
        );
        $context->builder->call($context->lookupFunction('free'), $pipeBuf);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $pipeResult
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    /**
     * Idempotent libc flock for thin AOT (#33122).
     *
     * NestedJIT StreamReadJitHelper→VmFs cannot see JitStreamIoKernel's FILE* table.
     * Replace the bridge after NestedJIT — peer fgets/fseek force (#27663).
     * php-src: ext/standard/flock_compat.c / file.c — PHP_FUNCTION(flock)
     */
    public static function implementFlockForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('flock_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_flock', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_flock');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_flock');
        }
        self::emitFlock($context, $fn);
        $context->registerFunction('__compiler_flock', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** libc FILE* → fileno → flock(2); ABI 1 on success, 0 on failure (#33122). */
    private static function emitFlock(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('flock_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $operation = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullPtr = $i8p->constNull();
        // php-src / VmPhpFdStream::phpLockToSys — PHP LOCK_UN=3 → OS LOCK_UN=8 (#33122).
        $phpLockUn = $i32->constInt(3, false);
        $phpLockSh = $i32->constInt(1, false);
        $phpLockEx = $i32->constInt(2, false);
        $phpLockNb = $i32->constInt(4, false);
        $sysLockUn = $i32->constInt(8, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('flock_fail');
        $filenoBb = $fn->appendBasicBlock('flock_fileno');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $filenoBb
        );

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $zero);
        $doBb = $fn->appendBasicBlock('flock_do');
        $context->builder->branchIf($fdBad, $failBb, $doBb);

        $context->builder->positionAtEnd($doBb);
        $opI32 = $context->builder->trunc($operation, $i32);
        $hasUn = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($opI32, $phpLockUn),
            $phpLockUn
        );
        $opWithoutUn = $context->builder->select(
            $hasUn,
            $context->builder->and($opI32, $context->builder->not($phpLockUn)),
            $opI32
        );
        $sys = $context->builder->select($hasUn, $sysLockUn, $zero);
        $sys = $context->builder->or(
            $sys,
            $context->builder->select(
                $context->builder->icmp(
                    Builder::INT_NE,
                    $context->builder->and($opWithoutUn, $phpLockSh),
                    $zero
                ),
                $phpLockSh,
                $zero
            )
        );
        $sys = $context->builder->or(
            $sys,
            $context->builder->select(
                $context->builder->icmp(
                    Builder::INT_NE,
                    $context->builder->and($opWithoutUn, $phpLockEx),
                    $zero
                ),
                $phpLockEx,
                $zero
            )
        );
        $sys = $context->builder->or(
            $sys,
            $context->builder->select(
                $context->builder->icmp(
                    Builder::INT_NE,
                    $context->builder->and($opWithoutUn, $phpLockNb),
                    $zero
                ),
                $phpLockNb,
                $zero
            )
        );
        $rc = $context->builder->call($context->lookupFunction('flock'), $fd, $sys);
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $zero);
        $context->builder->returnValue($context->builder->select($ok, $one, $zero));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    /**
     * Idempotent libc fpassthru for thin AOT (#33122).
     *
     * NestedJIT StreamReadJitHelper→VmFs cannot see libc FILE* handles. Peer
     * {@see JitReadfileLibc} write(1,…) loop; php-src file.c PHP_FUNCTION(fpassthru).
     */
    public static function implementFpassthruForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fpassthru');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fpassthru_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fpassthru', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_fpassthru');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_fpassthru');
        }
        self::emitFpassthru($context, $fn);
        $context->registerFunction('__compiler_fpassthru', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * libc FILE* fread → write(1,…) passthru; returns bytes written or -1 (#33122).
     */
    private static function emitFpassthru(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fpassthru_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullPtr = $i8p->constNull();
        $zeroI64 = $i64->constInt(0, false);
        $minusOne = $i64->constInt(-1, true);
        $chunk = $sizeT->constInt(self::DEFAULT_BUFFER_SIZE, false);
        $stdoutFd = $i32->constInt(1, false);
        $oneSize = $sizeT->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('fpassthru_fail');
        $allocBb = $fn->appendBasicBlock('fpassthru_alloc');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $allocBb
        );

        $context->builder->positionAtEnd($allocBb);
        $totalSlot = $context->builder->alloca($i64, 1, 'fpassthru_total');
        $context->builder->store($zeroI64, $totalSlot);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $chunk);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $loopHead = $fn->appendBasicBlock('fpassthru_loop_head');
        $context->builder->branchIf($bufNull, $failBb, $loopHead);

        $context->builder->positionAtEnd($loopHead);
        $nRead = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $oneSize,
            $chunk,
            $fp
        );
        $nReadI64 = $context->builder->zExt($nRead, $i64);
        $noMore = $context->builder->icmp(Builder::INT_EQ, $nReadI64, $zeroI64);
        $loopBody = $fn->appendBasicBlock('fpassthru_loop_body');
        $loopDone = $fn->appendBasicBlock('fpassthru_loop_done');
        $context->builder->branchIf($noMore, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $nWritten = $context->builder->call(
            $context->lookupFunction('write'),
            $stdoutFd,
            $buf,
            $nReadI64
        );
        $writeFail = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $nWritten, $zeroI64),
            $context->builder->icmp(Builder::INT_NE, $nWritten, $nReadI64)
        );
        $writeFailBb = $fn->appendBasicBlock('fpassthru_write_fail');
        $writeOkBb = $fn->appendBasicBlock('fpassthru_write_ok');
        $context->builder->branchIf($writeFail, $writeFailBb, $writeOkBb);

        $context->builder->positionAtEnd($writeFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($writeOkBb);
        $total = $context->builder->load($totalSlot);
        $context->builder->store($context->builder->add($total, $nReadI64), $totalSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($context->builder->load($totalSlot));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    /**
     * stream_supports() — LOCK true for libc FILE* except php://memory|temp (#19462).
     */
    private static function emitStreamSupports(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('stream_supports_llvm_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $feature = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $nullPtr = $i8p->constNull();
        // VmStreamSupports::STREAM_LOCK === 7
        $lockFeature = $i64->constInt(7, false);

        $notLockBb = $fn->appendBasicBlock('stream_supports_not_lock');
        $checkFpBb = $fn->appendBasicBlock('stream_supports_check_fp');
        $isLock = $context->builder->icmp(Builder::INT_EQ, $feature, $lockFeature);
        $context->builder->branchIf($isLock, $checkFpBb, $notLockBb);

        $context->builder->positionAtEnd($notLockBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($checkFpBb);
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $handle, $zeroI64),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $lookupBb = $fn->appendBasicBlock('stream_supports_lookup');
        $noBb = $fn->appendBasicBlock('stream_supports_no');
        $context->builder->branchIf($inRange, $lookupBb, $noBb);

        $context->builder->positionAtEnd($lookupBb);
        $fp = self::loadPtrSlot($context, self::GLOBAL_HANDLES, $handle);
        $hasFp = $context->builder->icmp(Builder::INT_NE, $fp, $nullPtr);
        $pathBb = $fn->appendBasicBlock('stream_supports_path');
        $context->builder->branchIf($hasFp, $pathBb, $noBb);

        $context->builder->positionAtEnd($pathBb);
        $path = self::loadPtrSlot($context, self::GLOBAL_PATHS, $handle);
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $yesBb = $fn->appendBasicBlock('stream_supports_yes');
        $memCheckBb = $fn->appendBasicBlock('stream_supports_mem_check');
        $context->builder->branchIf($pathNull, $yesBb, $memCheckBb);

        $context->builder->positionAtEnd($memCheckBb);
        $cmpMem = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'php://memory'),
            $context->getTypeFromString('size_t')->constInt(12, false)
        );
        $isMem = $context->builder->icmp(Builder::INT_EQ, $cmpMem, $context->getTypeFromString('int32')->constInt(0, false));
        $tempCheckBb = $fn->appendBasicBlock('stream_supports_temp_check');
        $context->builder->branchIf($isMem, $noBb, $tempCheckBb);

        $context->builder->positionAtEnd($tempCheckBb);
        $cmpTemp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $path,
            self::literalCstr($context, 'php://temp'),
            $context->getTypeFromString('size_t')->constInt(10, false)
        );
        $isTemp = $context->builder->icmp(Builder::INT_EQ, $cmpTemp, $context->getTypeFromString('int32')->constInt(0, false));
        $context->builder->branchIf($isTemp, $noBb, $yesBb);

        $context->builder->positionAtEnd($yesBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($noBb);
        $context->builder->returnValue($zeroI32);
    }

    /**
     * Idempotent libc fgetc for thin AOT (#33133).
     *
     * NestedJIT StreamReadJitHelper→VmFs cannot see JitStreamIoKernel FILE* handles.
     * Call only from forceLibcStreamPositionAbis after NestedJIT.
     * php-src: ext/standard/file.c — PHP_FUNCTION(fgetc)
     */
    public static function implementFgetcForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fgetc');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fgetc_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fgetc', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_fgetc');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_fgetc');
        }
        self::emitFgetc($context, $fn);
        $context->registerFunction('__compiler_fgetc', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** resolve → fgetc(3) → one-byte __string__* (null on EOF/error). */
    private static function emitFgetc(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fgetc_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $nullPtr = $i8p->constNull();
        $minusOne = $i32->constInt(-1, true);
        $oneI64 = $i64->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('fgetc_fail');
        $readBb = $fn->appendBasicBlock('fgetc_read');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $readBb
        );

        $context->builder->positionAtEnd($readBb);
        $ch = $context->builder->call($context->lookupFunction('fgetc'), $fp);
        $eofBb = $fn->appendBasicBlock('fgetc_eof');
        $makeBb = $fn->appendBasicBlock('fgetc_make');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $minusOne),
            $eofBb,
            $makeBb
        );

        $context->builder->positionAtEnd($eofBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($makeBb);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $sizeT->constInt(1, false)
        );
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $nullPtr);
        $storeBb = $fn->appendBasicBlock('fgetc_store');
        $context->builder->branchIf($bufNull, $failBb, $storeBb);

        $context->builder->positionAtEnd($storeBb);
        $context->builder->store($context->builder->trunc($ch, $i8), $buf);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $oneI64, $buf);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__compiler_stream_filter_apply_read'),
                $handle,
                $result
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    /**
     * Idempotent libc ftruncate for thin AOT (#33133).
     *
     * resolve→fileno→ftruncate(2). Call only from forceLibcStreamPositionAbis.
     * php-src: ext/standard/file.c — PHP_FUNCTION(ftruncate)
     */
    public static function implementFtruncateForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ftruncate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('ftruncate_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_ftruncate', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_ftruncate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_ftruncate');
        }
        self::emitFtruncate($context, $fn);
        $context->registerFunction('__compiler_ftruncate', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** resolve → fileno → ftruncate(2); ABI 1 on success, 0 on failure. */
    private static function emitFtruncate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ftruncate_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $size = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('ftruncate_fail');
        $filenoBb = $fn->appendBasicBlock('ftruncate_fileno');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $filenoBb
        );

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $doBb = $fn->appendBasicBlock('ftruncate_do');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $fd, $zero),
            $failBb,
            $doBb
        );

        $context->builder->positionAtEnd($doBb);
        // fflush so pending fwrite buffers hit the fd before truncate (#33133).
        $context->builder->call($context->lookupFunction('fflush'), $fp);
        $rc = $context->builder->call($context->lookupFunction('ftruncate'), $fd, $size);
        $context->builder->returnValue(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $rc, $zero),
                $one,
                $zero
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    /**
     * Idempotent libc fflush for thin AOT (#33354).
     *
     * NestedJIT StreamLifecycleJitHelper→VmFs cannot see JitStreamIoKernel's FILE*
     * table — replace the bridge after NestedJIT (peer {@see implementFtruncateForce}).
     * ABI: 1 on success, 0 on failure (matches {@see JitFflush}).
     */
    public static function implementFflushForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fflush');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fflush_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fflush', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_fflush');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_fflush');
        }
        self::emitFflush($context, $fn);
        $context->registerFunction('__compiler_fflush', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** resolve → libc fflush; ABI 1 on success, 0 on failure. */
    private static function emitFflush(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('fflush_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('fflush_fail');
        $okBb = $fn->appendBasicBlock('fflush_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $okBb
        );

        $context->builder->positionAtEnd($okBb);
        $rc = $context->builder->call($context->lookupFunction('fflush'), $fp);
        $context->builder->returnValue(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $rc, $zero),
                $one,
                $zero
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zero);
    }

    /**
     * Idempotent libc feof for thin AOT (#33555).
     *
     * NestedJIT StreamLifecycleJitHelper::feofArgv cannot see JitStreamIoKernel's FILE*
     * table (php://temp → tmpfile()) and returns 1 for unrecognized handles — so user
     * feof() and SplFileObject::eof latch refresh stayed sticky-true. Peer
     * {@see implementFflushForce}.
     * ABI: 1 at EOF, 0 otherwise (matches {@see JitFeof} / StreamLifecycleJitHelper).
     */
    public static function implementFeofForce(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_feof');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('feof_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_feof', $probe);

                    return;
                }
                break;
            }
        }
        $savedBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();
        self::ensureStreamGlobals($context);
        $probe = $context->module->getNamedFunction('__compiler_feof');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $fn = self::declareFunction($context, '__compiler_feof');
        }
        self::emitFeof($context, $fn);
        $context->registerFunction('__compiler_feof', $fn);
        if (null !== $savedBlock) {
            \PHPCompiler\JIT\BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** resolve → libc feof; ABI 1 at EOF, 0 otherwise. */
    private static function emitFeof(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('feof_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);

        $fp = $context->builder->call($context->lookupFunction('__phpc_resolve_stream'), $handle);
        $failBb = $fn->appendBasicBlock('feof_fail');
        $okBb = $fn->appendBasicBlock('feof_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $failBb,
            $okBb
        );

        $context->builder->positionAtEnd($okBb);
        $rc = $context->builder->call($context->lookupFunction('feof'), $fp);
        // libc feof returns non-zero at EOF; normalize to 0|1 ABI.
        $context->builder->returnValue(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_NE, $rc, $zero),
                $one,
                $zero
            )
        );

        $context->builder->positionAtEnd($failBb);
        // Unresolved handle → treat as EOF (closed / invalid), peer NestedJIT fallback.
        $context->builder->returnValue($one);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after JitStreamIoKernel implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
