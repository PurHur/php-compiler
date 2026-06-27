<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM process helpers — quarantined from embed PHP bridge (#9337).
 *
 * php-src: ext/standard/exec.c — shell_exec, escapeshellarg, escapeshellcmd
 * Embed SSOT: {@see ProcessJitHelper} via {@see ProcessRuntime}
 */
final class ProcessStandaloneLlvm
{
    private const CHUNK = 4096;

    private const TYPE_STRING = 4;

    private const STDOUT_FILENO = 1;

    private const STDERR_FILENO = 2;

    private const EXIT_127 = 127;

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_shell_exec');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__phpc_process_read_stream_all', self::emitReadStreamAll(...));
        self::implementIfMissing($context, '__phpc_process_read_stream_lines', self::emitReadStreamLines(...));
        self::implementIfMissing($context, '__phpc_process_apply_env', self::emitApplyEnv(...));
        self::implementIfMissing($context, '__compiler_shell_exec', self::emitShellExec(...));
        self::implementIfMissing($context, '__compiler_escapeshellarg', self::emitEscapeshellarg(...));
        self::implementIfMissing($context, '__compiler_escapeshellcmd', self::emitEscapeshellcmd(...));
        self::implementIfMissing($context, '__compiler_phpc_run_command', self::emitPhpcRunCommand(...));
        self::implementIfMissing($context, '__compiler_process_exec_capture', self::emitProcessExecCapture(...));

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
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

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');

        return match ($name) {
            '__phpc_process_read_stream_all' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_process_read_stream_lines' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $i8p)
            ),
            '__phpc_process_apply_env' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $htPtr)
            ),
            '__compiler_shell_exec' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            '__compiler_escapeshellarg' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            '__compiler_escapeshellcmd' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            ),
            '__compiler_phpc_run_command' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $htPtr)
            ),
            '__compiler_process_exec_capture' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr)
            ),
            default => throw new \LogicException('Unknown process runtime helper: '.$name),
        };
    }

    private static function emitReadStreamAll(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('prs_entry');
        $context->builder->positionAtEnd($entry);

        $fp = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidPtr = $context->getTypeFromString('void*');
        $zeroI64 = $i64->constInt(0, false);
        $chunkI64 = $i64->constInt(self::CHUNK, false);
        $chunkSizeT = $sizeT->constInt(self::CHUNK, false);

        $chunk = $context->builder->alloca($i8, self::CHUNK, 'prs_chunk');
        $chunkPtr = $context->builder->pointerCast($chunk, $i8p);
        $cap = $context->builder->alloca($sizeT, 1, 'prs_cap');
        $len = $context->builder->alloca($sizeT, 1, 'prs_len');
        $bufSlot = $context->builder->alloca($i8p, 1, 'prs_buf');
        $context->builder->store($chunkSizeT, $cap);
        $context->builder->store($sizeT->constInt(0, false), $len);

        $initial = $context->builder->call($context->lookupFunction('malloc'), $chunkSizeT);
        $initialNull = $context->builder->icmp(Builder::INT_EQ, $initial, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('prs_malloc_fail');
        $loopHead = $fn->appendBasicBlock('prs_loop_head');
        $context->builder->branchIf($initialNull, $failBb, $loopHead);

        $context->builder->positionAtEnd($loopHead);
        $context->builder->store($context->builder->pointerCast($initial, $i8p), $bufSlot);

        $context->builder->positionAtEnd($loopHead);
        $line = $context->builder->call(
            $context->lookupFunction('fgets'),
            $chunkPtr,
            $i32->constInt(self::CHUNK, false),
            $fp
        );
        $done = $context->builder->icmp(Builder::INT_EQ, $line, $i8p->constNull());
        $loopBody = $fn->appendBasicBlock('prs_loop_body');
        $loopDone = $fn->appendBasicBlock('prs_loop_done');
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $chunkLen = $context->builder->call($context->lookupFunction('strlen'), $chunkPtr);
        $curLen = $context->builder->load($len);
        $curCap = $context->builder->load($cap);
        $need = $context->builder->add($curLen, $chunkLen);
        $needPlusOne = $context->builder->add($need, $sizeT->constInt(1, false));
        $needGrow = $context->builder->icmp(Builder::INT_UGT, $needPlusOne, $curCap);
        $growBb = $fn->appendBasicBlock('prs_grow');
        $appendBb = $fn->appendBasicBlock('prs_append');
        $context->builder->branchIf($needGrow, $growBb, $appendBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->mul($needPlusOne, $sizeT->constInt(2, false));
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($context->builder->load($bufSlot)),
            $newCap
        );
        $grownNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $growFail = $fn->appendBasicBlock('prs_grow_fail');
        $growOk = $fn->appendBasicBlock('prs_grow_ok');
        $context->builder->branchIf($grownNull, $growFail, $growOk);

        $context->builder->positionAtEnd($growFail);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($bufSlot))
        );
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($growOk);
        $context->builder->store($context->builder->pointerCast($grown, $i8p), $bufSlot);
        $context->builder->store($newCap, $cap);
        $context->builder->branch($appendBb);

        $context->builder->positionAtEnd($appendBb);
        $curLen = $context->builder->load($len);
        $dest = $context->builder->gep($context->builder->load($bufSlot), $curLen);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($dest),
            $context->bytePtr($chunkPtr),
            $chunkLen
        );
        $context->builder->store($context->builder->add($curLen, $chunkLen), $len);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $finalLen = $context->builder->load($len);
        $empty = $context->builder->icmp(Builder::INT_EQ, $finalLen, $sizeT->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('prs_empty');
        $buildBb = $fn->appendBasicBlock('prs_build');
        $context->builder->branchIf($empty, $emptyBb, $buildBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($bufSlot))
        );
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $zeroI64,
                self::literalCstr($context, '')
            )
        );

        $context->builder->positionAtEnd($buildBb);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($finalLen, $i64),
            $context->builder->load($bufSlot)
        );
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($bufSlot))
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    /** Read FILE* into packed __hashtable__ of stdout lines (rtrim \\r\\n per line, #8640). */
    private static function emitReadStreamLines(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('prl_entry');
        $context->builder->positionAtEnd($entry);

        $fp = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $chunkSizeT = $sizeT->constInt(self::CHUNK, false);

        $chunk = $context->builder->alloca($i8, self::CHUNK, 'prl_chunk');
        $chunkPtr = $context->builder->pointerCast($chunk, $i8p);
        $indexSlot = $context->builder->alloca($sizeT, 1, 'prl_index');
        $context->builder->store($sizeT->constInt(0, false), $indexSlot);

        $result = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $htPtr->constNull());
        $failBb = $fn->appendBasicBlock('prl_malloc_fail');
        $loopHead = $fn->appendBasicBlock('prl_loop_head');
        $context->builder->branchIf($resultNull, $failBb, $loopHead);

        $context->builder->positionAtEnd($loopHead);
        $line = $context->builder->call(
            $context->lookupFunction('fgets'),
            $chunkPtr,
            $i32->constInt(self::CHUNK, false),
            $fp
        );
        $done = $context->builder->icmp(Builder::INT_EQ, $line, $i8p->constNull());
        $loopBody = $fn->appendBasicBlock('prl_loop_body');
        $loopDone = $fn->appendBasicBlock('prl_loop_done');
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $chunkLen = $context->builder->call($context->lookupFunction('strlen'), $chunkPtr);
        $lenSlot = $context->builder->alloca($sizeT, 1, 'prl_len');
        $context->builder->store($chunkLen, $lenSlot);
        $trimHead = $fn->appendBasicBlock('prl_trim_head');
        $trimBody = $fn->appendBasicBlock('prl_trim_body');
        $storeBb = $fn->appendBasicBlock('prl_store');
        $context->builder->branch($trimHead);

        $context->builder->positionAtEnd($trimHead);
        $curLen = $context->builder->load($lenSlot);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $curLen, $sizeT->constInt(0, false));
        $context->builder->branchIf($zeroLen, $storeBb, $trimBody);

        $context->builder->positionAtEnd($trimBody);
        $idx = $context->builder->sub($context->builder->load($lenSlot), $sizeT->constInt(1, false));
        $ch = $context->builder->load($context->builder->gep($chunkPtr, $idx));
        $isCr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false));
        $isNl = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false));
        $trimIt = $context->builder->or($isCr, $isNl);
        $trimCont = $fn->appendBasicBlock('prl_trim_cont');
        $context->builder->branchIf($trimIt, $trimCont, $storeBb);

        $context->builder->positionAtEnd($trimCont);
        $context->builder->store($idx, $lenSlot);
        $context->builder->branch($trimHead);

        $context->builder->positionAtEnd($storeBb);
        $finalLen = $context->builder->load($lenSlot);
        $lineStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($finalLen, $i64),
            $chunkPtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $result,
            $context->builder->load($indexSlot),
            $lineStr
        );
        $context->builder->store(
            $context->builder->add($context->builder->load($indexSlot), $sizeT->constInt(1, false)),
            $indexSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function emitApplyEnv(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pae_entry');
        $context->builder->positionAtEnd($entry);

        $env = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nullEnv = $context->builder->icmp(Builder::INT_EQ, $env, $htPtr->constNull());
        $nullBb = $fn->appendBasicBlock('pae_null');
        $initBb = $fn->appendBasicBlock('pae_init');
        $loopHead = $fn->appendBasicBlock('pae_loop_head');
        $context->builder->branchIf($nullEnv, $nullBb, $initBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($initBb);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($env, $htMap['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($loopHead);

        $loopBody = $fn->appendBasicBlock('pae_loop_body');
        $loopAdvance = $fn->appendBasicBlock('pae_loop_advance');
        $loopDone = $fn->appendBasicBlock('pae_loop_done');

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $typeByte = $context->builder->load($context->builder->structGep($valField, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isString = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(self::TYPE_STRING, false));
        $skipBb = $fn->appendBasicBlock('pae_skip');
        $setBb = $fn->appendBasicBlock('pae_set');
        $context->builder->branchIf($isString, $setBb, $skipBb);

        $context->builder->positionAtEnd($setBb);
        $valStr = $context->builder->call($context->lookupFunction('__value__readString'), $valField);
        $eitherNull = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $keyStr, $context->getTypeFromString('__string__*')->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $valStr, $context->getTypeFromString('__string__*')->constNull())
        );
        $doSetBb = $fn->appendBasicBlock('pae_do_set');
        $afterSet = $fn->appendBasicBlock('pae_after_set');
        $context->builder->branchIf($eitherNull, $afterSet, $doSetBb);

        $context->builder->positionAtEnd($doSetBb);
        $context->builder->call(
            $context->lookupFunction('setenv'),
            self::stringData($context, $keyStr),
            self::stringData($context, $valStr),
            $i32->constInt(1, false)
        );
        $context->builder->branch($afterSet);

        $context->builder->positionAtEnd($afterSet);
        $context->builder->branch($loopAdvance);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($loopAdvance);

        $context->builder->positionAtEnd($loopAdvance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnVoid();
    }

    private static function emitShellExec(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('se_entry');
        $context->builder->positionAtEnd($entry);

        $cmd = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $nullCmd = $context->builder->icmp(Builder::INT_EQ, $cmd, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('se_fail');
        $checkEmpty = $fn->appendBasicBlock('se_check_empty');
        $context->builder->branchIf($nullCmd, $failBb, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $command = self::stringData($context, $cmd);
        $first = $context->builder->load($command);
        $empty = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(0, false));
        $popenBb = $fn->appendBasicBlock('se_popen');
        $context->builder->branchIf($empty, $failBb, $popenBb);

        $context->builder->positionAtEnd($popenBb);
        $fp = $context->builder->call(
            $context->lookupFunction('popen'),
            $command,
            self::literalCstr($context, 'r')
        );
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $context->getTypeFromString('int8*')->constNull());
        $readBb = $fn->appendBasicBlock('se_read');
        $context->builder->branchIf($fpNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $result = $context->builder->call(
            $context->lookupFunction('__phpc_process_read_stream_all'),
            $fp
        );
        $closeRet = $context->builder->call($context->lookupFunction('pclose'), $fp);
        $closeFail = $context->builder->icmp(Builder::INT_EQ, $closeRet, $i32->constInt(-1, true));
        $checkClose = $fn->appendBasicBlock('se_check_close');
        $okBb = $fn->appendBasicBlock('se_ok');
        $context->builder->branchIf($closeFail, $checkClose, $okBb);

        $context->builder->positionAtEnd($checkClose);
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());
        $resultEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->select(
                $resultNull,
                $i64->constInt(0, false),
                self::stringLen($context, $result)
            ),
            $i64->constInt(0, false)
        );
        $closeBad = $context->builder->or($resultNull, $resultEmpty);
        $context->builder->branchIf($closeBad, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    /** popen + read all stdout + pclose status → hashtable {output, status} (#8640, phase 2 #3278). */
    private static function emitProcessExecCapture(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pec_entry');
        $context->builder->positionAtEnd($entry);

        $cmd = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullCmd = $context->builder->icmp(Builder::INT_EQ, $cmd, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('pec_fail');
        $checkEmpty = $fn->appendBasicBlock('pec_check_empty');
        $context->builder->branchIf($nullCmd, $failBb, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $command = self::stringData($context, $cmd);
        $first = $context->builder->load($command);
        $empty = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(0, false));
        $popenBb = $fn->appendBasicBlock('pec_popen');
        $context->builder->branchIf($empty, $failBb, $popenBb);

        $context->builder->positionAtEnd($popenBb);
        $fp = $context->builder->call(
            $context->lookupFunction('popen'),
            $command,
            self::literalCstr($context, 'r')
        );
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $context->getTypeFromString('int8*')->constNull());
        $readBb = $fn->appendBasicBlock('pec_read');
        $context->builder->branchIf($fpNull, $failBb, $readBb);

        $context->builder->positionAtEnd($readBb);
        $lines = $context->builder->call(
            $context->lookupFunction('__phpc_process_read_stream_lines'),
            $fp
        );
        $closeRet = $context->builder->call($context->lookupFunction('pclose'), $fp);
        $closeFail = $context->builder->icmp(Builder::INT_EQ, $closeRet, $i32->constInt(-1, true));
        $buildBb = $fn->appendBasicBlock('pec_build');
        $context->builder->branchIf($closeFail, $failBb, $buildBb);

        $context->builder->positionAtEnd($buildBb);
        $linesNull = $context->builder->icmp(Builder::INT_EQ, $lines, $htPtr->constNull());
        $linesFail = $fn->appendBasicBlock('pec_lines_fail');
        $linesOk = $fn->appendBasicBlock('pec_lines_ok');
        $context->builder->branchIf($linesNull, $linesFail, $linesOk);

        $context->builder->positionAtEnd($linesFail);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($linesOk);
        $result = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $htPtr->constNull());
        $resultFail = $fn->appendBasicBlock('pec_result_fail');
        $resultOk = $fn->appendBasicBlock('pec_result_ok');
        $context->builder->branchIf($resultNull, $resultFail, $resultOk);

        $context->builder->positionAtEnd($resultFail);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($resultOk);
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setHt = $context->lookupFunction('__hashtable__setStringKeyHashtable');
        $context->builder->call(
            $setHt,
            $result,
            self::literalKeyString($context, 'lines'),
            $lines
        );
        $context->builder->call(
            $setLong,
            $result,
            self::literalKeyString($context, 'status'),
            $context->builder->sext($closeRet, $i64)
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function emitEscapeshellarg(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('esa_entry');
        $context->builder->positionAtEnd($entry);

        $arg = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $nullArg = $context->builder->icmp(Builder::INT_EQ, $arg, $strPtr->constNull());
        $nullBb = $fn->appendBasicBlock('esa_null');
        $workBb = $fn->appendBasicBlock('esa_work');
        $context->builder->branchIf($nullArg, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                self::literalCstr($context, "''")
            )
        );

        $context->builder->positionAtEnd($workBb);
        $src = self::stringData($context, $arg);
        $srcLen = self::stringLen($context, $arg);
        $srcLenSizeT = $context->builder->truncOrBitCast($srcLen, $sizeT);
        $failBb = $fn->appendBasicBlock('esa_fail');
        $allocBb = $fn->appendBasicBlock('esa_alloc');
        self::emitRejectShellNullBytes(
            $context,
            $fn,
            $workBb,
            $src,
            $srcLenSizeT,
            'escapeshellarg(): Argument #1 ($arg) must not contain any null bytes',
            $allocBb
        );
        $context->builder->positionAtEnd($allocBb);
        $outCap = $context->builder->add(
            $context->builder->mul($srcLenSizeT, $sizeT->constInt(4, false)),
            $sizeT->constInt(3, false)
        );
        $outSlot = $context->builder->alloca($i8p, 1, 'esa_out');
        $outLenSlot = $context->builder->alloca($sizeT, 1, 'esa_out_len');
        $outCapSlot = $context->builder->alloca($sizeT, 1, 'esa_out_cap');
        $iSlot = $context->builder->alloca($sizeT, 1, 'esa_i');
        $out = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $i8p->constNull());
        $initBb = $fn->appendBasicBlock('esa_init');
        $context->builder->branchIf($outNull, $failBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $outPtr = $context->builder->pointerCast($out, $i8p);
        $context->builder->store($outPtr, $outSlot);
        $context->builder->store($sizeT->constInt(0, false), $outLenSlot);
        $context->builder->store($outCap, $outCapSlot);
        $context->builder->store($i8->constInt(39, false), $context->builder->gep($outPtr, $sizeT->constInt(0, false)));
        $context->builder->store($sizeT->constInt(1, false), $outLenSlot);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('esa_loop_head');
        $loopBody = $fn->appendBasicBlock('esa_loop_body');
        $loopDone = $fn->appendBasicBlock('esa_loop_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $srcLenSizeT);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($src, $i));
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(39, false));
        $quoteBb = $fn->appendBasicBlock('esa_quote');
        $plainBb = $fn->appendBasicBlock('esa_plain');
        $afterBb = $fn->appendBasicBlock('esa_after');
        $context->builder->branchIf($isQuote, $quoteBb, $plainBb);

        $context->builder->positionAtEnd($quoteBb);
        self::ensureOutRoom($context, $fn, $outSlot, $outLenSlot, $outCapSlot, $sizeT->constInt(4, false));
        $outLen = $context->builder->load($outLenSlot);
        $outPtr = $context->builder->load($outSlot);
        $context->builder->store($i8->constInt(39, false), $context->builder->gep($outPtr, $outLen));
        $context->builder->store($i8->constInt(92, false), $context->builder->gep($outPtr, $context->builder->add($outLen, $sizeT->constInt(1, false))));
        $context->builder->store($i8->constInt(39, false), $context->builder->gep($outPtr, $context->builder->add($outLen, $sizeT->constInt(2, false))));
        $context->builder->store($i8->constInt(39, false), $context->builder->gep($outPtr, $context->builder->add($outLen, $sizeT->constInt(3, false))));
        $context->builder->store($context->builder->add($outLen, $sizeT->constInt(4, false)), $outLenSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($plainBb);
        self::ensureOutRoom($context, $fn, $outSlot, $outLenSlot, $outCapSlot, $sizeT->constInt(1, false));
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($ch, $context->builder->gep($context->builder->load($outSlot), $outLen));
        $context->builder->store($context->builder->add($outLen, $sizeT->constInt(1, false)), $outLenSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        self::ensureOutRoom($context, $fn, $outSlot, $outLenSlot, $outCapSlot, $sizeT->constInt(1, false));
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($i8->constInt(39, false), $context->builder->gep($context->builder->load($outSlot), $outLen));
        $context->builder->store($context->builder->add($outLen, $sizeT->constInt(1, false)), $outLenSlot);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($context->builder->load($outLenSlot), $i64),
            $context->builder->load($outSlot)
        );
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($outSlot))
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitEscapeshellcmd(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('esc_entry');
        $context->builder->positionAtEnd($entry);

        $cmdIn = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $nullIn = $context->builder->icmp(Builder::INT_EQ, $cmdIn, $strPtr->constNull());
        $emptyInBb = $fn->appendBasicBlock('esc_empty_in');
        $checkLen = $fn->appendBasicBlock('esc_check_len');
        $context->builder->branchIf($nullIn, $emptyInBb, $checkLen);

        $context->builder->positionAtEnd($emptyInBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                self::literalCstr($context, '')
            )
        );

        $context->builder->positionAtEnd($checkLen);
        $str = self::stringData($context, $cmdIn);
        $l = self::stringLen($context, $cmdIn);
        $lSizeT = $context->builder->truncOrBitCast($l, $sizeT);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $lSizeT, $sizeT->constInt(0, false));
        $workBb = $fn->appendBasicBlock('esc_work');
        $context->builder->branchIf($zeroLen, $emptyInBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $allocBb = $fn->appendBasicBlock('esc_alloc');
        self::emitRejectShellNullBytes(
            $context,
            $fn,
            $workBb,
            $str,
            $lSizeT,
            'escapeshellcmd(): Argument #1 ($command) must not contain any null bytes',
            $allocBb
        );
        $context->builder->positionAtEnd($allocBb);
        $outCap = $context->builder->add($context->builder->mul($lSizeT, $sizeT->constInt(2, false)), $sizeT->constInt(1, false));
        $outSlot = $context->builder->alloca($i8p, 1, 'esc_out');
        $outLenSlot = $context->builder->alloca($sizeT, 1, 'esc_out_len');
        $outCapSlot = $context->builder->alloca($sizeT, 1, 'esc_out_cap');
        $xSlot = $context->builder->alloca($sizeT, 1, 'esc_x');
        $pSlot = $context->builder->alloca($i8p, 1, 'esc_p');
        $out = $context->builder->call($context->lookupFunction('malloc'), $outCap);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('esc_fail');
        $initBb = $fn->appendBasicBlock('esc_init');
        $context->builder->branchIf($outNull, $failBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $context->builder->store($context->builder->pointerCast($out, $i8p), $outSlot);
        $context->builder->store($sizeT->constInt(0, false), $outLenSlot);
        $context->builder->store($outCap, $outCapSlot);
        $context->builder->store($sizeT->constInt(0, false), $xSlot);
        $context->builder->store($i8p->constNull(), $pSlot);
        $loopHead = $fn->appendBasicBlock('esc_loop_head');
        $loopBody = $fn->appendBasicBlock('esc_loop_body');
        $loopDone = $fn->appendBasicBlock('esc_loop_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $x = $context->builder->load($xSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $x, $lSizeT);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($str, $x));
        $isQuote = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(34, false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(39, false))
        );
        $quoteBb = $fn->appendBasicBlock('esc_quote');
        $specialBb = $fn->appendBasicBlock('esc_special');
        $plainBb = $fn->appendBasicBlock('esc_plain');
        $emitChar = $fn->appendBasicBlock('esc_emit_char');
        $notQuoteBb = $fn->appendBasicBlock('esc_not_quote');
        $context->builder->branchIf($isQuote, $quoteBb, $notQuoteBb);

        $context->builder->positionAtEnd($notQuoteBb);
        $needsSlash = self::escapeshellcmdNeedsSlash($context, $ch);
        $context->builder->branchIf($needsSlash, $specialBb, $plainBb);

        $context->builder->positionAtEnd($quoteBb);
        $pVal = $context->builder->load($pSlot);
        $pNull = $context->builder->icmp(Builder::INT_EQ, $pVal, $i8p->constNull());
        $quoteSearchBb = $fn->appendBasicBlock('esc_quote_search');
        $quotePairBb = $fn->appendBasicBlock('esc_quote_pair');
        $quoteSlashBb = $fn->appendBasicBlock('esc_quote_slash');
        $context->builder->branchIf($pNull, $quoteSearchBb, $quotePairBb);

        $context->builder->positionAtEnd($quoteSearchBb);
        $remain = $context->builder->sub($lSizeT, $context->builder->add($x, $sizeT->constInt(1, false)));
        $found = $context->builder->call(
            $context->lookupFunction('memchr'),
            $context->builder->gep($str, $context->builder->add($x, $sizeT->constInt(1, false))),
            $context->builder->zExt($ch, $i32),
            $remain
        );
        $foundNull = $context->builder->icmp(Builder::INT_EQ, $found, $i8p->constNull());
        $quoteAfterSearch = $fn->appendBasicBlock('esc_quote_after_search');
        $context->builder->branchIf($foundNull, $quoteSlashBb, $quoteAfterSearch);
        $context->builder->positionAtEnd($quoteAfterSearch);
        $context->builder->store($found, $pSlot);
        $context->builder->branch($emitChar);

        $context->builder->positionAtEnd($quotePairBb);
        $same = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($pVal), $ch);
        $quoteClearBb = $fn->appendBasicBlock('esc_quote_clear');
        $context->builder->branchIf($same, $quoteClearBb, $quoteSlashBb);
        $context->builder->positionAtEnd($quoteClearBb);
        $context->builder->store($i8p->constNull(), $pSlot);
        $context->builder->branch($emitChar);

        $context->builder->positionAtEnd($quoteSlashBb);
        self::appendSlash($context, $fn, $outSlot, $outLenSlot, $outCapSlot);
        $context->builder->branch($emitChar);

        $context->builder->positionAtEnd($specialBb);
        self::appendSlash($context, $fn, $outSlot, $outLenSlot, $outCapSlot);
        $context->builder->branch($emitChar);

        $context->builder->positionAtEnd($plainBb);
        $context->builder->branch($emitChar);

        $context->builder->positionAtEnd($emitChar);
        self::ensureOutRoom($context, $fn, $outSlot, $outLenSlot, $outCapSlot, $sizeT->constInt(1, false));
        $y = $context->builder->load($outLenSlot);
        $context->builder->store($ch, $context->builder->gep($context->builder->load($outSlot), $y));
        $context->builder->store($context->builder->add($y, $sizeT->constInt(1, false)), $outLenSlot);
        $context->builder->store($context->builder->add($context->builder->load($xSlot), $sizeT->constInt(1, false)), $xSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($context->builder->load($outLenSlot), $i64),
            $context->builder->load($outSlot)
        );
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($outSlot))
        );
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function emitPhpcRunCommand(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('prc_entry');
        $context->builder->positionAtEnd($entry);

        $cmd = $fn->getParam(0);
        $env = $fn->getParam(1);
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullCmd = $context->builder->icmp(Builder::INT_EQ, $cmd, $strPtr->constNull());
        $failBb = $fn->appendBasicBlock('prc_fail');
        $checkEmpty = $fn->appendBasicBlock('prc_check_empty');
        $context->builder->branchIf($nullCmd, $failBb, $checkEmpty);

        $context->builder->positionAtEnd($checkEmpty);
        $command = self::stringData($context, $cmd);
        $first = $context->builder->load($command);
        $empty = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(0, false));
        $pipeBb = $fn->appendBasicBlock('prc_pipe');
        $context->builder->branchIf($empty, $failBb, $pipeBb);

        $context->builder->positionAtEnd($pipeBb);
        $stdoutPipe = $context->builder->alloca($i32, 2, 'prc_stdout_pipe');
        $stderrPipe = $context->builder->alloca($i32, 2, 'prc_stderr_pipe');
        $stdoutRc = $context->builder->call($context->lookupFunction('pipe'), $stdoutPipe);
        $stderrRc = $context->builder->call($context->lookupFunction('pipe'), $stderrPipe);
        $pipeFail = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $stdoutRc, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $stderrRc, $i32->constInt(0, false))
        );
        $forkBb = $fn->appendBasicBlock('prc_fork');
        $context->builder->branchIf($pipeFail, $failBb, $forkBb);

        $context->builder->positionAtEnd($forkBb);
        $pid = $context->builder->call($context->lookupFunction('fork'));
        $forkFail = $context->builder->icmp(Builder::INT_EQ, $pid, $i32->constInt(-1, true));
        $forkCleanup = $fn->appendBasicBlock('prc_fork_cleanup');
        $childBb = $fn->appendBasicBlock('prc_child');
        $parentBb = $fn->appendBasicBlock('prc_parent');
        $forkRole = $fn->appendBasicBlock('prc_fork_role');
        $context->builder->branchIf($forkFail, $forkCleanup, $forkRole);

        $context->builder->positionAtEnd($forkRole);
        $isChild = $context->builder->icmp(Builder::INT_EQ, $pid, $i32->constInt(0, false));
        $context->builder->branchIf($isChild, $childBb, $parentBb);

        $context->builder->positionAtEnd($forkCleanup);
        self::closePipePair($context, $stdoutPipe);
        self::closePipePair($context, $stderrPipe);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($childBb);
        self::closeReadEnd($context, $stdoutPipe);
        self::closeReadEnd($context, $stderrPipe);
        self::dupPipeWriteTo($context, $stdoutPipe, self::STDOUT_FILENO);
        self::dupPipeWriteTo($context, $stderrPipe, self::STDERR_FILENO);
        self::closeWriteEnd($context, $stdoutPipe);
        self::closeWriteEnd($context, $stderrPipe);
        $context->builder->call($context->lookupFunction('__phpc_process_apply_env'), $env);
        $context->builder->call(
            $context->lookupFunction('execl'),
            self::literalCstr($context, '/bin/sh'),
            self::literalCstr($context, 'sh'),
            self::literalCstr($context, '-c'),
            $command,
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->call($context->lookupFunction('_exit'), $i32->constInt(self::EXIT_127, false));

        $context->builder->positionAtEnd($parentBb);
        self::closeWriteEnd($context, $stdoutPipe);
        self::closeWriteEnd($context, $stderrPipe);
        $stdoutFp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            self::pipeReadFd($context, $stdoutPipe),
            self::literalCstr($context, 'r')
        );
        $stderrFp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            self::pipeReadFd($context, $stderrPipe),
            self::literalCstr($context, 'r')
        );
        $stdoutStr = self::readStreamOrNull($context, $stdoutFp);
        $stderrStr = self::readStreamOrNull($context, $stderrFp);
        self::closeStreamOrFd($context, $fn, $stdoutFp, $stdoutPipe);
        self::closeStreamOrFd($context, $fn, $stderrFp, $stderrPipe);

        $statusSlot = $context->builder->alloca($i32, 1, 'prc_status');
        $waitRc = $context->builder->call(
            $context->lookupFunction('waitpid'),
            $pid,
            $statusSlot,
            $i32->constInt(0, false)
        );
        $exitCodeSlot = $context->builder->alloca($i32, 1, 'prc_exit_code');
        $waitFailBb = $fn->appendBasicBlock('prc_wait_fail');
        $waitOkBb = $fn->appendBasicBlock('prc_wait_ok');
        $waitFail = $context->builder->icmp(Builder::INT_EQ, $waitRc, $i32->constInt(-1, true));
        $context->builder->branchIf($waitFail, $waitFailBb, $waitOkBb);

        $context->builder->positionAtEnd($waitFailBb);
        $context->builder->store($i32->constInt(self::EXIT_127, false), $exitCodeSlot);
        $buildBb = $fn->appendBasicBlock('prc_build');
        $context->builder->branch($buildBb);

        $context->builder->positionAtEnd($waitOkBb);
        $status = $context->builder->load($statusSlot);
        $exited = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($status, $i32->constInt(0xff, false)),
            $i32->constInt(0, false)
        );
        $exitOkBb = $fn->appendBasicBlock('prc_exit_ok');
        $exitBadBb = $fn->appendBasicBlock('prc_exit_bad');
        $context->builder->branchIf($exited, $exitOkBb, $exitBadBb);

        $context->builder->positionAtEnd($exitOkBb);
        $context->builder->store(
            $context->builder->and(
                $context->builder->lShr($status, $i32->constInt(8, false)),
                $i32->constInt(0xff, false)
            ),
            $exitCodeSlot
        );
        $context->builder->branch($buildBb);

        $context->builder->positionAtEnd($exitBadBb);
        $context->builder->store($i32->constInt(self::EXIT_127, false), $exitCodeSlot);
        $context->builder->branch($buildBb);

        $context->builder->positionAtEnd($buildBb);
        $stdoutFinal = self::coalesceEmptyString($context, $stdoutStr);
        $stderrFinal = self::coalesceEmptyString($context, $stderrStr);
        $result = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $resultNull = $context->builder->icmp(Builder::INT_EQ, $result, $htPtr->constNull());
        $resultFail = $fn->appendBasicBlock('prc_result_fail');
        $resultOk = $fn->appendBasicBlock('prc_result_ok');
        $context->builder->branchIf($resultNull, $resultFail, $resultOk);

        $context->builder->positionAtEnd($resultFail);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($resultOk);
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $context->builder->call(
            $setLong,
            $result,
            self::literalKeyString($context, 'code'),
            $context->builder->sext($context->builder->load($exitCodeSlot), $i64)
        );
        $context->builder->call($setString, $result, self::literalKeyString($context, 'stdout'), $stdoutFinal);
        $context->builder->call($setString, $result, self::literalKeyString($context, 'stderr'), $stderrFinal);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());
    }

    private static function readStreamOrNull(Context $context, Value $fp): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());

        return $context->builder->select(
            $fpNull,
            $strPtr->constNull(),
            $context->builder->call($context->lookupFunction('__phpc_process_read_stream_all'), $fp)
        );
    }

    private static function coalesceEmptyString(Context $context, Value $str): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull());

        return $context->builder->select(
            $isNull,
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                self::literalCstr($context, '')
            ),
            $str
        );
    }

    private static function closeStreamOrFd(Context $context, LlvmFunction $fn, Value $fp, Value $pipe): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $closeFpBb = $fn->appendBasicBlock('prc_close_fp_'.(++self::$blockSuffix));
        $closeFdBb = $fn->appendBasicBlock('prc_close_fd_'.self::$blockSuffix);
        $afterBb = $fn->appendBasicBlock('prc_close_done_'.self::$blockSuffix);
        $context->builder->branchIf($fpNull, $closeFdBb, $closeFpBb);

        $context->builder->positionAtEnd($closeFpBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($closeFdBb);
        $context->builder->call($context->lookupFunction('close'), self::pipeReadFd($context, $pipe));
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
    }

    private static function closePipePair(Context $context, Value $pipe): void
    {
        $i32 = $context->getTypeFromString('int32');
        $close = $context->lookupFunction('close');
        $context->builder->call($close, self::pipeReadFd($context, $pipe));
        $context->builder->call($close, self::pipeWriteFd($context, $pipe));
    }

    private static function closeReadEnd(Context $context, Value $pipe): void
    {
        $context->builder->call($context->lookupFunction('close'), self::pipeReadFd($context, $pipe));
    }

    private static function closeWriteEnd(Context $context, Value $pipe): void
    {
        $context->builder->call($context->lookupFunction('close'), self::pipeWriteFd($context, $pipe));
    }

    private static function dupPipeWriteTo(Context $context, Value $pipe, int $targetFd): void
    {
        $context->builder->call(
            $context->lookupFunction('dup2'),
            self::pipeWriteFd($context, $pipe),
            $context->getTypeFromString('int32')->constInt($targetFd, false)
        );
    }

    private static function pipeReadFd(Context $context, Value $pipe): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->load($context->builder->gep($pipe, $i32->constInt(0, false)));
    }

    private static function pipeWriteFd(Context $context, Value $pipe): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->load($context->builder->gep($pipe, $i32->constInt(1, false)));
    }

    private static function appendSlash(
        Context $context,
        LlvmFunction $fn,
        Value $outSlot,
        Value $outLenSlot,
        Value $outCapSlot
    ): void {
        self::ensureOutRoom(
            $context,
            $fn,
            $outSlot,
            $outLenSlot,
            $outCapSlot,
            $context->getTypeFromString('size_t')->constInt(1, false)
        );
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $y = $context->builder->load($outLenSlot);
        $context->builder->store(
            $i8->constInt(92, false),
            $context->builder->gep($context->builder->load($outSlot), $y)
        );
        $context->builder->store($context->builder->add($y, $sizeT->constInt(1, false)), $outLenSlot);
    }

    private static function ensureOutRoom(
        Context $context,
        LlvmFunction $fn,
        Value $outSlot,
        Value $outLenSlot,
        Value $outCapSlot,
        Value $needExtra
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $outLen = $context->builder->load($outLenSlot);
        $outCap = $context->builder->load($outCapSlot);
        $need = $context->builder->add($outLen, $needExtra);
        $ok = $context->builder->icmp(Builder::INT_ULT, $need, $outCap);
        $growBb = $fn->appendBasicBlock('por_grow_'.(++self::$blockSuffix));
        $doneBb = $fn->appendBasicBlock('por_done_'.self::$blockSuffix);
        $context->builder->branchIf($ok, $doneBb, $growBb);

        $context->builder->positionAtEnd($growBb);
        $newCap = $context->builder->mul($need, $sizeT->constInt(2, false));
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($context->builder->load($outSlot)),
            $newCap
        );
        $grownNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $failBb = $fn->appendBasicBlock('por_fail_'.self::$blockSuffix);
        $okBb = $fn->appendBasicBlock('por_ok_'.self::$blockSuffix);
        $context->builder->branchIf($grownNull, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('free'),
            $context->bytePtr($context->builder->load($outSlot))
        );
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->store($context->builder->pointerCast($grown, $i8p), $outSlot);
        $context->builder->store($newCap, $outCapSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function escapeshellcmdNeedsSlash(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $match = $i1->constInt(0, false);
        foreach ([35, 38, 59, 96, 124, 42, 63, 126, 60, 62, 94, 40, 41, 91, 93, 123, 125, 36, 92, 10, 255] as $ord) {
            $match = $context->builder->or(
                $match,
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt($ord, false))
            );
        }

        return $match;
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            self::literalCstr($context, $text)
        );
    }

    private static function emitRejectShellNullBytes(
        Context $context,
        LlvmFunction $fn,
        BasicBlock $fromBb,
        Value $src,
        Value $srcLenSizeT,
        string $message,
        BasicBlock $okBb,
    ): void {
        $context->builder->positionAtEnd($fromBb);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $found = $context->builder->call(
            $context->lookupFunction('memchr'),
            $src,
            $i32->constInt(0, false),
            $srcLenSizeT
        );
        $hasNul = $context->builder->icmp(Builder::INT_NE, $found, $i8p->constNull());
        $nulBb = $fn->appendBasicBlock('shell_nul_reject');
        $context->builder->branchIf($hasNul, $nulBb, $okBb);

        $context->builder->positionAtEnd($nulBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $i32->pointerType(0);

        foreach (
            [
                ['popen', $i8p, [$i8p, $i8p]],
                ['pclose', $i32, [$i8p]],
                ['fgets', $i8p, [$i8p, $i32, $i8p]],
                ['malloc', $voidPtr, [$sizeT]],
                ['realloc', $voidPtr, [$voidPtr, $sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
                ['memchr', $i8p, [$i8p, $i32, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
                ['setenv', $i32, [$i8p, $i8p, $i32]],
                ['pipe', $i32, [$i32p]],
                ['fork', $i32, []],
                ['dup2', $i32, [$i32, $i32]],
                ['close', $i32, [$i32]],
                ['fdopen', $i8p, [$i32, $i8p]],
                ['fclose', $i32, [$i8p]],
                ['waitpid', $i32, [$i32, $i32p, $i32]],
                ['execl', $i32, [$i8p, $i8p, $i8p, $i8p, $i8p]],
                ['_exit', $voidTy, [$i32]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
                ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyHashtable', $voidTy, [$htPtr, $strPtr, $htPtr]],
                ['__hashtable__setStringAt', $voidTy, [$htPtr, $sizeT, $strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__value__readString', $strPtr, [$valuePtr]],
                ['__phpc_process_read_stream_all', $strPtr, [$i8p]],
                ['__phpc_process_read_stream_lines', $htPtr, [$i8p]],
                ['__phpc_process_apply_env', $voidTy, [$htPtr]],
            ] as [$name, $ret, $params]
        ) {
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__phpc_process_read_stream_all',
                '__phpc_process_read_stream_lines',
                '__phpc_process_apply_env',
                '__compiler_shell_exec',
                '__compiler_escapeshellarg',
                '__compiler_escapeshellcmd',
                '__compiler_phpc_run_command',
                '__compiler_process_exec_capture',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ProcessRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
