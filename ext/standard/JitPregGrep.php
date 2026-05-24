<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_grep() via __compiler_preg_match (issue #1180). */
final class JitPregGrep
{
  private static int $blockSerial = 0;

  public static function invoke(Context $context, Value $pattern, Variable $array, Value $invert): Value
  {
    StringPregMatch::ensureLinked($context);

    $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
    $resultHt = self::buildGrepHashTable($context, $ht, $pattern, $invert);

    $slot = JitValueBox::alloc($context);
    $ptr = JitValueBox::pointer($context, $slot);
    $htPtr = $context->getTypeFromString('__hashtable__*');
    $isError = $context->builder->icmp(Builder::INT_EQ, $resultHt, $htPtr->constNull());

    $id = (string) (++self::$blockSerial);
    $failBlock = BasicBlockHelper::append($context, 'preg_grep_fail_'.$id);
    $okBlock = BasicBlockHelper::append($context, 'preg_grep_ok_'.$id);
    $doneBlock = BasicBlockHelper::append($context, 'preg_grep_done_'.$id);
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

  private static function buildGrepHashTable(Context $context, Value $src, Value $pattern, Value $invert): Value
  {
    $map = $context->structFieldMap['__hashtable__'];
    $sizeT = $context->getTypeFromString('size_t');
    $nextFree = $context->builder->load(
      $context->builder->structGep($src, $map['nextFreeElement'])
    );
    $zero = $sizeT->constInt(0, false);
    $one = $sizeT->constInt(1, false);
    $i64 = $context->getTypeFromString('int64');
    $errorSentinel = $i64->constInt(-1, true);
    $htPtr = $context->getTypeFromString('__hashtable__*');

    $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
    $emptyBlock = BasicBlockHelper::append($context, 'preg_grep_empty');
    $workBlock = BasicBlockHelper::append($context, 'preg_grep_work');
    $doneBlock = BasicBlockHelper::append($context, 'preg_grep_ht_done');
    $errorBlock = BasicBlockHelper::append($context, 'preg_grep_error');
    $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

    $context->builder->positionAtEnd($emptyBlock);
    $emptyHt = HashTableHelper::alloc($context);
    $context->builder->branch($doneBlock);

    $context->builder->positionAtEnd($workBlock);
    $dest = HashTableHelper::alloc($context);
    $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'preg_grep_src');
    $context->builder->store($zero, $srcIdxSlot);
    $head = BasicBlockHelper::append($context, 'preg_grep_head');
    $check = BasicBlockHelper::append($context, 'preg_grep_check');
    $matchBlock = BasicBlockHelper::append($context, 'preg_grep_match');
    $copyBlock = BasicBlockHelper::append($context, 'preg_grep_copy');
    $skipUnset = BasicBlockHelper::append($context, 'preg_grep_skip_unset');
    $skipNoMatch = BasicBlockHelper::append($context, 'preg_grep_skip_nomatch');
    $advance = BasicBlockHelper::append($context, 'preg_grep_advance');
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
    $context->builder->branchIf($isSet, $matchBlock, $skipUnset);

    $context->builder->positionAtEnd($matchBlock);
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
    $pregBlock = BasicBlockHelper::append($context, 'preg_grep_preg');
    $context->builder->branchIf($isString, $pregBlock, $skipUnset);

    $context->builder->positionAtEnd($pregBlock);
    $subject = $context->builder->call(
      $context->lookupFunction('__value__readString'),
      $entry
    );
    $raw = $context->builder->call(
      $context->lookupFunction('__compiler_preg_match'),
      $pattern,
      $subject
    );
    $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $errorSentinel);
    $context->builder->branchIf($isError, $errorBlock, $copyBlock);

    $context->builder->positionAtEnd($copyBlock);
    $matched = $context->builder->icmp(Builder::INT_EQ, $raw, $i64->constInt(1, false));
    $keep = $context->builder->select($invert, $context->builder->not($matched), $matched);
    $context->builder->branchIf($keep, $skipNoMatch, $skipUnset);

    $context->builder->positionAtEnd($skipNoMatch);
    self::copyListEntry($context, $src, $srcIdx, $dest, $srcIdx);
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

  private static function copyListEntry(
    Context $context,
    Value $src,
    Value $srcIndex,
    Value $dest,
    Value $destIndex
  ): void {
    $valueMap = $context->structFieldMap['__value__'];
    $srcEntry = self::listEntryAt($context, $src, $srcIndex);
    $typeByte = $context->builder->load(
      $context->builder->structGep($srcEntry, $valueMap['type'])
    );
    $i8 = $context->getTypeFromString('int8');
    $isString = $context->builder->icmp(
      Builder::INT_EQ,
      $typeByte,
      $i8->constInt(Variable::TYPE_STRING & 0xff, false)
    );
    $stringBlock = BasicBlockHelper::append($context, 'preg_grep_copy_str');
    $skipBlock = BasicBlockHelper::append($context, 'preg_grep_copy_skip');
    $done = BasicBlockHelper::append($context, 'preg_grep_copy_done');
    $context->builder->branchIf($isString, $stringBlock, $skipBlock);

    $context->builder->positionAtEnd($stringBlock);
    $context->builder->call(
      $context->lookupFunction('__hashtable__setStringAt'),
      $dest,
      $destIndex,
      $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
    );
    $context->builder->branch($done);

    $context->builder->positionAtEnd($skipBlock);
    $context->builder->branch($done);

    $context->builder->positionAtEnd($done);
  }
}
