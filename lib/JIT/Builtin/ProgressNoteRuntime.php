<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __phpc_progress_note (issue #6748).
 *
 * File writes mirror lib/JIT/Progress.php; SIGSEGV breadcrumb buffer stays in thin
 * lib/AOT/runtime/phpc_progress.c (__phpc_progress_remember).
 */
final class ProgressNoteRuntime
{
    private const ENV_PROGRESS = 'PHP_COMPILER_JIT_PROGRESS_FILE';

    private const ENV_PHASE = 'PHP_COMPILER_JIT_PHASE_FILE';

    private const ENV_ENTRY = 'PHP_COMPILER_JIT_ENTRY_FILE';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
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
        $context->builder->call($context->lookupFunction('__phpc_progress_remember'), $msg);
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
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');

        foreach (
            [
                'getenv' => [$i8p, false, [$i8p]],
                'strlen' => [$sizeT, false, [$i8p]],
                'fopen' => [$i8p, false, [$i8p, $i8p]],
                'fwrite' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
                'fclose' => [$i32, false, [$i8p]],
                '__phpc_progress_remember' => [$voidTy, false, [$i8p]],
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
            throw new \LogicException('__phpc_progress_note missing after ProgressNoteRuntime LLVM implement');
        }
        $context->registerFunction('__phpc_progress_note', $fn);
    }
}
