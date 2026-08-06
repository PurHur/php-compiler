<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

/**
 * LLVM lowering for getimagesize() / getimagesizefromstring() (#3271, #27291).
 *
 * Thin AOT: header parse + HT assemble in LLVM ({@see GetimagesizeParseLlvm}).
 * NestedJIT {@see __string__*} args / HashTable returns are empty or crash under
 * user-script AOT (peer #27051 / #26910 / #26829).
 */
final class JitGetimagesize
{
    private static int $seq = 0;

    public static function fromPath(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'getimagesize() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'getimagesize() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc >= 2) {
            throw new \LogicException('getimagesize() imageinfo by-ref is VM-only in this compiler build (#3271)');
        }
        StringFileGetContents::implement($context);
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'getimagesize', 0, 'filename');
        $data = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $path
        );

        return self::lowerBytesValue($context, $data, $path, 'getimagesize');
    }

    public static function fromBytes(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getimagesizefromstring() expects one or two arguments in this compiler build');
        }
        if ($argc >= 2) {
            throw new \LogicException('getimagesizefromstring() imageinfo by-ref is VM-only in this compiler build (#3271)');
        }
        $data = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'getimagesizefromstring', 0, 'string');

        return self::lowerBytesValue($context, $data, null, 'getimagesizefromstring', $data);
    }

    private static function lowerBytesValue(
        Context $context,
        Value $data,
        ?Value $pathForOpenWarning = null,
        string $function = 'getimagesize',
        ?Value $noticeSource = null
    ): Value {
        StringTriggerErrorJit::implement($context);

        $tag = 'getimagesize'.(string) ++self::$seq;
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $falseVal = $i1->constInt(0, false);
        $noticeSource ??= $pathForOpenWarning ?? $data;

        $missingData = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $openFailBlock = BasicBlockHelper::append($context, $tag.'_open_fail');
        $readFailBlock = BasicBlockHelper::append($context, $tag.'_read_fail');
        $parseBlock = BasicBlockHelper::append($context, $tag.'_parse');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');

        if (null !== $pathForOpenWarning) {
            $context->builder->branchIf($missingData, $openFailBlock, $parseBlock);
        } else {
            $context->builder->branchIf($missingData, $readFailBlock, $parseBlock);
        }

        $context->builder->positionAtEnd($parseBlock);
        $parsed = GetimagesizeParseLlvm::parse($context, $data);
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $context->builder->branchIf($parsed['ok'], $okBlock, $readFailBlock);

        if (null !== $pathForOpenWarning) {
            $context->builder->positionAtEnd($openFailBlock);
            JitBuiltinWarning::emitStreamOpenFailed($context, $pathForOpenWarning, $function);
            $openFailTail = self::writeFalseAndBranch($context, $doneBlock, $falseVal);
        } else {
            $openFailTail = null;
        }

        $context->builder->positionAtEnd($readFailBlock);
        $shouldNotice = null !== $pathForOpenWarning
            ? GetimagesizeParseLlvm::shouldNoticePath($context, $noticeSource)
            : GetimagesizeParseLlvm::shouldNoticeBytes($context, $noticeSource);
        $noticeEmitBlock = BasicBlockHelper::append($context, $tag.'_notice_emit');
        $readFailTailBlock = BasicBlockHelper::append($context, $tag.'_read_fail_tail');
        $context->builder->branchIf($shouldNotice, $noticeEmitBlock, $readFailTailBlock);

        $context->builder->positionAtEnd($noticeEmitBlock);
        JitBuiltinWarning::emitImageReadFailed($context, $noticeSource, $function);
        $context->builder->branch($readFailTailBlock);

        $context->builder->positionAtEnd($readFailTailBlock);
        $readFailTail = self::writeFalseAndBranch($context, $doneBlock, $falseVal);

        $context->builder->positionAtEnd($okBlock);
        $ht = self::assembleResultHashtable($context, $parsed, $i64, $sizeT);
        $context->refcount->addref($ht);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        if (null !== $openFailTail) {
            $result->addIncoming($openFailTail, $openFailBlock);
        }
        $result->addIncoming($readFailTail, $readFailTailBlock);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }

    /**
     * @param array{ok:Value,width:Value,height:Value,type:Value,bits:Value,channels:Value,mime:Value,attr:Value} $parsed
     */
    private static function assembleResultHashtable(Context $context, array $parsed, Type $i64, Type $sizeT): Value
    {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $setLongAt = $context->lookupFunction('__hashtable__setLongAt');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $setKeyLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setKeyString = $context->lookupFunction('__hashtable__setStringKeyString');

        $context->builder->call($setLongAt, $ht, $sizeT->constInt(0, false), $parsed['width']);
        $context->builder->call($setLongAt, $ht, $sizeT->constInt(1, false), $parsed['height']);
        $context->builder->call($setLongAt, $ht, $sizeT->constInt(2, false), $parsed['type']);
        $context->builder->call($setStringAt, $ht, $sizeT->constInt(3, false), $parsed['attr']);
        $context->builder->call(
            $setKeyLong,
            $ht,
            $context->builder->load($context->constantStringFromString('bits')),
            $parsed['bits']
        );
        $context->builder->call(
            $setKeyString,
            $ht,
            $context->builder->load($context->constantStringFromString('mime')),
            $parsed['mime']
        );

        $hasChannels = $context->builder->icmp(
            Builder::INT_SGE,
            $parsed['channels'],
            $i64->constInt(0, true)
        );
        $withCh = BasicBlockHelper::append($context, 'getimagesize_with_channels');
        $afterCh = BasicBlockHelper::append($context, 'getimagesize_after_channels');
        $context->builder->branchIf($hasChannels, $withCh, $afterCh);

        $context->builder->positionAtEnd($withCh);
        $context->builder->call(
            $setKeyLong,
            $ht,
            $context->builder->load($context->constantStringFromString('channels')),
            $parsed['channels']
        );
        $context->builder->branch($afterCh);

        $context->builder->positionAtEnd($afterCh);

        return $ht;
    }

    private static function writeFalseAndBranch(Context $context, \PHPLLVM\BasicBlock $doneBlock, Value $falseVal): Value
    {
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $falseVal);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        return $failPtr;
    }
}
