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
 * LLVM stream I/O for standalone / user-script AOT — fopen/fread/fwrite/tmpfile (#5343, #19462, #19530, #26929).
 *
 * Embed JIT uses {@see \PHPCompiler\JIT\Builtin\StreamIoRuntime} + {@see StreamIoJitHelper} PHP.
 * User-script AOT cannot nested-JIT VmFs helpers (ExternalMethod → handle 0, #16075) —
 * this libc + {@see StreamGlobalsJit} handle-table path is the user-script SSOT.
 * Restored after #20943 NestedJIT-only regression blocked fsync/fwrite under thin AOT (#26929).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19500 / #19466.
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
        LibcExtern::ensureStrcmpDecl($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            ['__phpc_resolve_stream', $i8p, [$i64]],
            ['__string__strlen', $i64, [$strPtr]],
            ['__string__init', $strPtr, [$i64, $i8p]],
            ['fwrite', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fopen', $i8p, [$i8p, $i8p]],
            ['popen', $i8p, [$i8p, $i8p]],
            ['pclose', $i32, [$i8p]],
            ['fclose', $i32, [$i8p]],
            ['tmpfile', $i8p, []],
            ['strdup', $i8p, [$i8p]],
            ['free', $void, [$i8p]],
            ['malloc', $i8p, [$sizeT]],
            ['fread', $sizeT, [$i8p, $sizeT, $sizeT, $i8p]],
            ['fgets', $i8p, [$i8p, $i32, $i8p]],
            ['fseek', $i32, [$i8p, $i64, $i32]],
            ['ftell', $i64, [$i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['ferror', $i32, [$i8p]],
            ['strcmp', $i32, [$i8p, $i8p]],
            ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
            ['dup', $i32, [$i32]],
            ['fdopen', $i8p, [$i32, $i8p]],
            ['close', $i32, [$i32]],
            ['__compiler_stream_filter_apply_write', $strPtr, [$i64, $strPtr]],
            ['__compiler_stream_filter_apply_read', $strPtr, [$i64, $strPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
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
            $phpMemNull = $context->builder->icmp(Builder::INT_EQ, $phpMemFp, $nullPtr);
            $context->builder->branchIf($phpMemNull, $plainBb, $phpMemOkBb);

            $context->builder->positionAtEnd($phpMemOkBb);
            $phpMemTail = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($plainBb);
            $plainFp = $context->builder->call(
                $context->lookupFunction('fopen'),
                self::stringData($context, $path),
                self::stringData($context, $mode)
            );
            $plainTail = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($mergeBb);
            $fpPhi = $context->builder->phi($i8p, $prefix.'_fp');
            $fpPhi->addIncoming($stdioFp, $openBb);
            $fpPhi->addIncoming($phpMemFp, $phpMemOkBb);
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
        $gotLen = $context->builder->call($context->lookupFunction('strlen'), $buf);
        $gotLenI64 = $context->builder->zExt($gotLen, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $gotLenI64, $buf);
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
        $context->builder->positionAtEnd($sizeBb);
        $pos = $context->builder->call($context->lookupFunction('ftell'), $fp);
        $posBad = $context->builder->icmp(Builder::INT_SLT, $pos, $zeroI64);
        $endSeekBb = $fn->appendBasicBlock('sgc_end_seek');
        $context->builder->branchIf($posBad, $failBb, $endSeekBb);

        $context->builder->positionAtEnd($endSeekBb);
        $endSeekRc = $context->builder->call(
            $context->lookupFunction('fseek'),
            $fp,
            $zeroI64,
            $i32->constInt(2, false) // SEEK_END
        );
        $endSeekFail = $context->builder->icmp(Builder::INT_NE, $endSeekRc, $zeroI32);
        $endTellBb = $fn->appendBasicBlock('sgc_end_tell');
        $context->builder->branchIf($endSeekFail, $failBb, $endTellBb);

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

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
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
