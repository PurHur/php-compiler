<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __phpc_progress_note for AOT standalone link (#6748, #9521).
 *
 * JIT path uses {@see ProgressNoteRuntime} + {@see ProgressJitHelper} PHP; standalone keeps
 * full LLVM until compiled PHP static storage is reliable in native link (same as LastErrorRuntimeLlvm).
 */
final class ProgressNoteRuntimeLlvm
{
    private const ENV_PROGRESS = 'PHP_COMPILER_JIT_PROGRESS_FILE';

    private const ENV_PHASE = 'PHP_COMPILER_JIT_PHASE_FILE';

    private const ENV_ENTRY = 'PHP_COMPILER_JIT_ENTRY_FILE';

    /** Must match phpc_progress.c extern buffer size. */
    private const BUFFER_SIZE = 256;

    private const GLOBAL_BUF = 'phpc_last_progress';

    private const GLOBAL_LEN = 'phpc_last_progress_len';

    private static int $blockSuffix = 0;

    /** @var Value|null */
    private static $bufGlobal = null;

    /** @var Value|null */
    private static $lenGlobal = null;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
        self::registerStaticProxies($context);
    }

    /** Register Progress::{noteFunction,notePhase,noteEntry} before spine callees compile (#8560). */
    public static function registerStaticProxies(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr);
        $savedBuilder = $context->builder;
        foreach ([
            'phpcompiler\\jit\\progress::notefunction' => 'phpc_progress_notefunction_stub',
            'phpcompiler\\jit\\progress::notephase' => 'phpc_progress_notephase_stub',
            'phpcompiler\\jit\\progress::noteentry' => 'phpc_progress_noteentry_stub',
        ] as $proxy => $internal) {
            if ($context->functionIsRegistered($proxy)) {
                continue;
            }
            $probe = $context->module->getNamedFunction($internal);
            $fn = (null !== $probe && $probe->countBasicBlocks() > 0)
                ? $probe
                : $context->module->addFunction($internal, $ft);
            if (0 === $fn->countBasicBlocks()) {
                $entry = $fn->appendBasicBlock('entry');
                $context->builder = $context->context->builderCreate();
                $context->builder->positionAtEnd($entry);
                $context->builder->returnVoid();
                $context->builder->clearInsertionPosition();
            }
            $context->registerFunction($proxy, $fn);
            $context->functions[$proxy] = $fn;
            $context->functionProxies[$proxy] = new Call\Native($fn, $proxy, [$strPtr], []);
            $context->functionReturnType[$proxy] = 'void';
        }
        $context->builder = $savedBuilder;
    }

    public static function emitCall(Context $context, string $message): void
    {
        if ('' === $message) {
            return;
        }
        try {
            $fn = $context->lookupFunction('__phpc_progress_note');
        } catch (\Throwable $e) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $fn,
            $context->builder->pointerCast($context->constantFromString($message), $i8p)
        );
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_progress_note');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureProgressGlobals($context);
        self::ensureExternals($context);

        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__phpc_progress_note', $ft);
        self::implementNote($context, $fn);
        $context->registerFunction('__phpc_progress_note', $fn);
    }

    private static function ensureProgressGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $bufType = $i8->arrayType(self::BUFFER_SIZE);

        if (null === $context->module->getNamedGlobal(self::GLOBAL_BUF)) {
            self::$bufGlobal = $context->module->addGlobal($bufType, self::GLOBAL_BUF);
            self::$bufGlobal->setInitializer($bufType->constNull());
        } else {
            self::$bufGlobal = $context->module->getNamedGlobal(self::GLOBAL_BUF);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LEN)) {
            self::$lenGlobal = $context->module->addGlobal($sizeT, self::GLOBAL_LEN);
            self::$lenGlobal->setInitializer($sizeT->constInt(0, false));
        } else {
            self::$lenGlobal = $context->module->getNamedGlobal(self::GLOBAL_LEN);
        }
    }

    private static function bufBasePtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
    }

    private static function implementNote(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('progress_entry');
        $context->builder->positionAtEnd($entry);

        $msg = $fn->getParam(0);
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $msg, $nullPtr);
        $done = $fn->appendBasicBlock('progress_done');
        $body = $fn->appendBasicBlock('progress_body');
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        self::emitRememberToBuffer($context, $fn, $msg);
        $afterProgress = self::appendBlock($fn, 'progress_after_progress');
        $afterPhase = self::appendBlock($fn, 'progress_after_phase');
        self::emitWriteEnvFile($context, $fn, $msg, self::ENV_PROGRESS, $afterProgress);
        $context->builder->positionAtEnd($afterProgress);
        self::emitWriteEnvFile($context, $fn, $msg, self::ENV_PHASE, $afterPhase);
        $context->builder->positionAtEnd($afterPhase);
        self::emitWriteEnvFile($context, $fn, $msg, self::ENV_ENTRY, $done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitRememberToBuffer(Context $context, LlvmFunction $fn, Value $msg): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $maxLen = $sizeT->constInt(self::BUFFER_SIZE - 1, false);
        $zeroByte = $i8->constInt(0, false);
        $zeroSize = $sizeT->constInt(0, false);

        $len = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $clamp = self::appendBlock($fn, 'progress_clamp_len');
        $okLen = self::appendBlock($fn, 'progress_ok_len');
        $copy = self::appendBlock($fn, 'progress_copy');
        $doCopy = self::appendBlock($fn, 'progress_do_copy');
        $skipCopy = self::appendBlock($fn, 'progress_skip_copy');
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $len, $maxLen);
        $context->builder->branchIf($tooLong, $clamp, $okLen);

        $context->builder->positionAtEnd($clamp);
        $context->builder->branch($copy);

        $context->builder->positionAtEnd($okLen);
        $context->builder->branch($copy);

        $context->builder->positionAtEnd($copy);
        $storedLen = $context->builder->phi($sizeT);
        $storedLen->addIncoming($maxLen, $clamp);
        $storedLen->addIncoming($len, $okLen);
        $context->builder->store($storedLen, self::$lenGlobal);

        $hasLen = $context->builder->icmp(Builder::INT_UGT, $storedLen, $zeroSize);
        $context->builder->branchIf($hasLen, $doCopy, $skipCopy);

        $context->builder->positionAtEnd($doCopy);
        $bufPtr = self::bufBasePtr($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($bufPtr),
            $context->bytePtr($msg),
            $storedLen
        );
        $context->builder->branch($skipCopy);

        $context->builder->positionAtEnd($skipCopy);
        $bufPtr = self::bufBasePtr($context);
        $termPtr = $context->builder->inBoundsGEP($bufPtr, $storedLen);
        $context->builder->store($zeroByte, $termPtr);
    }

    private static function emitWriteEnvFile(
        Context $context,
        LlvmFunction $fn,
        Value $msg,
        string $envKey,
        \PHPLLVM\BasicBlock $next
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullPtr = $i8p->constNull();
        $oneSizeT = $sizeT->constInt(1, false);
        $zeroI8 = $i8->constInt(0, false);

        $envName = $context->builder->pointerCast(
            $context->constantFromString($envKey),
            $i8p
        );
        $path = $context->builder->call($context->lookupFunction('getenv'), $envName);

        $skip = self::appendBlock($fn, 'progress_skip_'.$envKey);
        $hasPath = self::appendBlock($fn, 'progress_has_path_'.$envKey);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $nullPtr);
        $context->builder->branchIf($isNull, $skip, $hasPath);

        $context->builder->positionAtEnd($hasPath);
        $firstByte = $context->builder->load($path);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $firstByte, $zeroI8);
        $open = self::appendBlock($fn, 'progress_open_'.$envKey);
        $context->builder->branchIf($isEmpty, $skip, $open);

        $context->builder->positionAtEnd($open);
        $mode = $context->builder->pointerCast($context->constantFromString('wb'), $i8p);
        $stream = $context->builder->call($context->lookupFunction('fopen'), $path, $mode);
        $isOpenFail = $context->builder->icmp(Builder::INT_EQ, $stream, $nullPtr);
        $write = self::appendBlock($fn, 'progress_write_'.$envKey);
        $context->builder->branchIf($isOpenFail, $skip, $write);

        $context->builder->positionAtEnd($write);
        $len = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $hasLen = self::appendBlock($fn, 'progress_has_len_'.$envKey);
        $noLen = self::appendBlock($fn, 'progress_no_len_'.$envKey);
        $lenNonZero = $context->builder->icmp(Builder::INT_NE, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($lenNonZero, $hasLen, $noLen);

        $context->builder->positionAtEnd($hasLen);
        $context->builder->call(
            $context->lookupFunction('fwrite'),
            $msg,
            $oneSizeT,
            $len,
            $stream
        );
        $context->builder->branch($noLen);

        $context->builder->positionAtEnd($noLen);
        $context->builder->call($context->lookupFunction('fclose'), $stream);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($next);
    }

    private static function appendBlock(LlvmFunction $fn, string $label): \PHPLLVM\BasicBlock
    {
        return $fn->appendBasicBlock($label.'_'.(++self::$blockSuffix));
    }

    private static function ensureExternals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $i32 = $context->getTypeFromString('int32');

        foreach (
            [
                'getenv' => [$i8p, false, [$i8p]],
                'strlen' => [$sizeT, false, [$i8p]],
                'memcpy' => [$voidPtr, false, [$voidPtr, $voidPtr, $sizeT]],
                'fopen' => [$i8p, false, [$i8p, $i8p]],
                'fwrite' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
                'fclose' => [$i32, false, [$i8p]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_progress_note');
        if (null === $fn) {
            throw new \LogicException('__phpc_progress_note missing after ProgressNoteRuntimeLlvm LLVM implement');
        }
        $context->registerFunction('__phpc_progress_note', $fn);
    }
}
