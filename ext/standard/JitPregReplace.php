<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_replace() via __compiler_preg_replace (issue #1176, array subject #4055). */
final class JitPregReplace
{
    private static int $blockSerial = 0;

    public static function invokeString(
        Context $context,
        Value $pattern,
        Value $replacement,
        Value $subject,
        ?Value $limit = null
    ): Value {
        StringPregMatch::ensureLinked($context);

        return self::replaceString($context, $pattern, $replacement, $subject, $limit ?? self::unlimitedLimit($context));
    }

    public static function invokeArray(
        Context $context,
        Value $pattern,
        Value $replacement,
        Variable $subject,
        ?Value $limit = null
    ): Value {
        StringPregMatch::ensureLinked($context);
        $limitVal = $limit ?? self::unlimitedLimit($context);

        $ht = ArrayBuiltinHelper::loadHashTable($context, $subject);
        $resultHt = self::buildReplaceHashTable($context, $ht, $pattern, $replacement, $limitVal);

        return self::wrapHashTableResult($context, $resultHt);
    }

    private static function unlimitedLimit(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(-1, false);
    }

    public static function returnNullEmptyPattern(Context $context, string $function): Value
    {
        StringPregMatch::ensureLinked($context);
        StringTriggerErrorJit::implement($context);
        $message = $function.'(): Empty regular expression';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    private static function replaceString(
        Context $context,
        Value $pattern,
        Value $replacement,
        Value $subjectStr,
        Value $limit
    ): Value {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace'),
            $pattern,
            $replacement,
            $subjectStr,
            $limit
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_replace_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_replace_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function wrapHashTableResult(Context $context, Value $resultHt): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isError = $context->builder->icmp(Builder::INT_EQ, $resultHt, $htPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_replace_arr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_replace_arr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_arr_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $resultHt);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function buildReplaceHashTable(
        Context $context,
        Value $src,
        Value $pattern,
        Value $replacement,
        Value $limit
    ): Value {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtrTy = $context->getTypeFromString('__string__*');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'preg_replace_empty_'.$id);
        $workBlock = BasicBlockHelper::append($context, 'preg_replace_work_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_ht_done_'.$id);
        $errorBlock = BasicBlockHelper::append($context, 'preg_replace_error_'.$id);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'preg_replace_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'preg_replace_head_'.$id);
        $check = BasicBlockHelper::append($context, 'preg_replace_check_'.$id);
        $replaceBlock = BasicBlockHelper::append($context, 'preg_replace_replace_'.$id);
        $skipUnset = BasicBlockHelper::append($context, 'preg_replace_skip_unset_'.$id);
        $advance = BasicBlockHelper::append($context, 'preg_replace_advance_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $replaceBlock, $skipUnset);

        $context->builder->positionAtEnd($replaceBlock);
        $entry = self::listEntryAt($context, $src, $srcIdx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $pregBlock = BasicBlockHelper::append($context, 'preg_replace_preg_'.$id);
        $context->builder->branchIf($isString, $pregBlock, $skipUnset);

        $context->builder->positionAtEnd($pregBlock);
        $subject = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
        $replaced = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace'),
            $pattern,
            $replacement,
            $subject,
            $limit
        );
        $replaceFailed = $context->builder->icmp(Builder::INT_EQ, $replaced, $strPtrTy->constNull());
        $storeBlock = BasicBlockHelper::append($context, 'preg_replace_store_'.$id);
        $context->builder->branchIf($replaceFailed, $errorBlock, $storeBlock);

        $context->builder->positionAtEnd($storeBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $srcIdx,
            $replaced
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($errorBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);
        $phi->addIncoming($htPtr->constNull(), $errorBlock);

        return $phi;
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }
}
