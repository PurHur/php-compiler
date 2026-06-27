<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GetimagesizeJit;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Context;
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
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getimagesize() expects one or two arguments in this compiler build');
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

        return self::lowerBytesValue($context, $data);
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
        $data = JitStringBuiltinArg::lower($context, $args[0], 'getimagesizefromstring', 0, 'data');

        return self::lowerBytesValue($context, $data);
    }

    private static function lowerBytesValue(Context $context, Value $data): Value
    {
        GetimagesizeJit::ensureLinked($context);

        $tag = 'getimagesize'.(string) ++self::$seq;
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        $missingData = $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull());
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $parseBlock = BasicBlockHelper::append($context, $tag.'_parse');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($missingData, $failBlock, $parseBlock);

        $context->builder->positionAtEnd($parseBlock);
        $ht = $context->builder->call(GetimagesizeJit::helperFunction($context), $data);
        $parseFailed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $context->builder->branchIf($parseFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $falseVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->refcount->addref($ht);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($failPtr, $failBlock);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }
}
