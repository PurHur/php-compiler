<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetimagesizeJit;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for getimagesize() / getimagesizefromstring() (#3271). */
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
        $data = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'getimagesizefromstring', 0, 'string');

        return self::lowerBytesValue($context, $data, null, 'getimagesizefromstring', $data);
    }

    private static function lowerBytesValue(
        Context $context,
        Value $data,
        ?Value $pathForOpenWarning = null,
        string $function = 'getimagesize',
        ?Value $noticeSource = null
    ): Value {
        GetimagesizeJit::ensureLinked($context);
        StringTriggerErrorJit::implement($context);

        $tag = 'getimagesize'.(string) ++self::$seq;
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
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
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            GetimagesizeJit::helperFunction($context),
            [$data]
        );
        $parseFailed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $context->builder->branchIf($parseFailed, $readFailBlock, $okBlock);

        if (null !== $pathForOpenWarning) {
            $context->builder->positionAtEnd($openFailBlock);
            JitBuiltinWarning::emitStreamOpenFailed($context, $pathForOpenWarning, $function);
            $openFailTail = self::writeFalseAndBranch($context, $doneBlock, $falseVal);
        } else {
            $openFailTail = null;
        }

        $context->builder->positionAtEnd($readFailBlock);
        $shouldNoticeFn = null !== $pathForOpenWarning
            ? GetimagesizeJit::shouldNoticeForPathHelper($context)
            : GetimagesizeJit::shouldNoticeForBytesHelper($context);
        $shouldNotice = $context->builder->call($shouldNoticeFn, $noticeSource);
        $shouldNoticeBool = $context->builder->icmp(Builder::INT_NE, $shouldNotice, $i1->constInt(0, false));
        $noticeEmitBlock = BasicBlockHelper::append($context, $tag.'_notice_emit');
        $readFailTailBlock = BasicBlockHelper::append($context, $tag.'_read_fail_tail');
        $context->builder->branchIf($shouldNoticeBool, $noticeEmitBlock, $readFailTailBlock);

        $context->builder->positionAtEnd($noticeEmitBlock);
        JitBuiltinWarning::emitImageReadFailed($context, $noticeSource, $function);
        $context->builder->branch($readFailTailBlock);

        $context->builder->positionAtEnd($readFailTailBlock);
        $readFailTail = self::writeFalseAndBranch($context, $doneBlock, $falseVal);

        $context->builder->positionAtEnd($okBlock);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
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
