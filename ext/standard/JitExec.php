<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Builtin\ProcessRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for exec()/passthru()/system() via ProcessRuntime (#8640, phase 2 #3278). */
final class JitExec
{
    public static function exec(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('exec() accepts one to three arguments in this compiler build');
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'exec', 'command', 1);

        $cmd = JitStringBuiltinArg::lower($context, $args[0], 'exec', 0, 'command');
        self::rejectEmptyCommand($context, $args[0], $cmd, 'exec');
        $capture = self::capture($context, $cmd);
        $failed = $context->builder->icmp(
            Builder::INT_EQ,
            $capture,
            $context->getTypeFromString('__hashtable__*')->constNull()
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'exec_fail');
        $okBlock = BasicBlockHelper::append($context, 'exec_ok');
        $doneBlock = BasicBlockHelper::append($context, 'exec_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $lines = self::readLines($context, $capture);
        if ($argc >= 2) {
            self::writeHashtableRef($context, $args[1], $lines);
        }
        if ($argc >= 3) {
            self::writeStatusRef($context, $args[2], self::readStatus($context, $capture));
        }
        $lastLine = self::lastLine($context, $lines);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $lastLine
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function passthru(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('passthru() accepts one or two arguments in this compiler build');
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'passthru', 'command', 1);

        return self::runWithStdout($context, $args[0], $argc >= 2 ? $args[1] : null, false, 'passthru');
    }

    public static function system(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('system() accepts one or two arguments in this compiler build');
        }

        JitInternalStrictArg::rejectNullString($context, $args[0], 'system', 'command', 1);

        return self::runWithStdout($context, $args[0], $argc >= 2 ? $args[1] : null, true, 'system');
    }

    private static function runWithStdout(
        Context $context,
        JITVariable $commandArg,
        ?JITVariable $statusArg,
        bool $returnLastLine,
        string $function
    ): Value {
        $cmd = JitStringBuiltinArg::lower($context, $commandArg, $function, 0, 'command');
        self::rejectEmptyCommand($context, $commandArg, $cmd, $function);
        $capture = self::capture($context, $cmd);
        $failed = $context->builder->icmp(
            Builder::INT_EQ,
            $capture,
            $context->getTypeFromString('__hashtable__*')->constNull()
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'proc_fail');
        $okBlock = BasicBlockHelper::append($context, 'proc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'proc_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $lines = self::readLines($context, $capture);
        $writeOk = self::writeLinesToStdout($context, $lines);
        $writeFailBlock = BasicBlockHelper::append($context, 'proc_write_fail');
        $writeOkBlock = BasicBlockHelper::append($context, 'proc_write_ok');
        $context->builder->branchIf($writeOk, $writeOkBlock, $writeFailBlock);

        $context->builder->positionAtEnd($writeFailBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($writeOkBlock);
        if (null !== $statusArg) {
            self::writeStatusRef($context, $statusArg, self::readStatus($context, $capture));
        }
        if ($returnLastLine) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                self::lastLine($context, $lines)
            );
        } else {
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function capture(Context $context, Value $cmdStr): Value
    {
        ProcessRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_process_exec_capture'),
            $cmdStr
        );
    }

    private static function readLines(Context $context, Value $captureHt): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $captureHt,
            self::literalKey($context, 'lines')
        );
    }

    private static function readStatus(Context $context, Value $captureHt): Value
    {
        $valuePtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $captureHt,
            self::literalKey($context, 'status')
        );

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    private static function writeStatusRef(Context $context, JITVariable $statusArg, Value $status): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::valuePtrFromVariable($context, $statusArg),
            $status
        );
    }

    private static function writeHashtableRef(Context $context, JITVariable $outArg, Value $linesHt): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::valuePtrFromVariable($context, $outArg),
            $linesHt
        );
    }

    private static function lastLine(Context $context, Value $linesHt): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $count = $context->builder->load($context->builder->structGep($linesHt, $map['nextFreeElement']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $count, $sizeT->constInt(0, false));
        $emptyBlock = BasicBlockHelper::append($context, 'exec_last_empty');
        $lastBlock = BasicBlockHelper::append($context, 'exec_last_line');
        $mergeBlock = BasicBlockHelper::append($context, 'exec_last_done');
        $lastSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->branchIf($empty, $emptyBlock, $lastBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                self::literalCstr($context, '')
            ),
            $lastSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($lastBlock);
        $lastIndex = $context->builder->sub($count, $sizeT->constInt(1, false));
        $context->builder->store(
            HashTableHelper::readStringAt($context, $linesHt, $lastIndex),
            $lastSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($lastSlot);
    }

    /** @return Value int1 — true when all lines were written */
    private static function writeLinesToStdout(Context $context, Value $linesHt): Value
    {
        ObOutputRuntime::ensureLinked($context);

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $count = $context->builder->load($context->builder->structGep($linesHt, $map['nextFreeElement']));
        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $indexSlot);

        $nl = self::literalCstr($context, "\n");
        $echoSubstr = $context->lookupFunction('__phpc_ob_echo_substr');
        $echoCstr = $context->lookupFunction('__phpc_ob_echo_cstr');

        $loopHead = BasicBlockHelper::append($context, 'exec_stdout_head');
        $loopBody = BasicBlockHelper::append($context, 'exec_stdout_body');
        $loopDone = BasicBlockHelper::append($context, 'exec_stdout_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $index = $context->builder->load($indexSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $index, $count);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $line = HashTableHelper::readStringAt($context, $linesHt, $index);
        $lineMap = $context->structFieldMap['__string__'];
        $lineData = $context->builder->load($context->builder->structGep($line, $lineMap['value']));
        $lineLen = $context->builder->load($context->builder->structGep($line, $lineMap['length']));
        $lineLenSizeT = $context->builder->truncOrBitCast($lineLen, $sizeT);
        $context->builder->call(
            $echoSubstr,
            $context->builder->pointerCast($lineData, $i8p),
            $lineLenSizeT
        );
        $context->builder->call($echoCstr, $nl);
        $context->builder->store(
            $context->builder->add($index, $sizeT->constInt(1, false)),
            $indexSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);

        return $i1->constInt(1, false);
    }

    private static function literalKey(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function rejectEmptyCommand(
        Context $context,
        JITVariable $arg,
        Value $lowered,
        string $function
    ): void {
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $arg,
            $lowered,
            \sprintf('%s(): Argument #1 ($command) cannot be empty', $function)
        );
    }
}
