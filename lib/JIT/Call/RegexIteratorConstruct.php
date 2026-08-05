<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableSliceLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * RegexIterator::__construct — thin AOT MATCH filter into `__spl_ht` (#26825, #27313).
 *
 * Walks inner HT via key/value pair export (peer ParentIterator / HashTableSliceLlvm)
 * so string-keyed ArrayIterator entries are not skipped. Packed-only index walks
 * missed assoc keys and yielded empty under AOT (#27313).
 *
 * Match hits via `__compiler_preg_match` + {@see PregAotFastPath} kind 9 (`/^lit/`).
 * Keys are preserved; int-key holes sealed UNDEFINED so foreach skips them (#27581).
 *
 * php-src: ext/spl/spl_iterators.c — RegexIterator MATCH mode
 */
final class RegexIteratorConstruct implements Call
{
    private static int $seq = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('RegexIterator::__construct() called without $this');
        }
        if (!isset($args[1], $args[2])) {
            throw new \ArgumentCountError(
                'RegexIterator::__construct() expects at least 2 arguments, '
                .(\count($args) - 1).' given'
            );
        }

        StringPregMatch::ensureLinked($context);
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        $pattern = self::loadString($context, $args[2]);
        $filtered = self::filterMatchKeyed($context, $srcHt, $pattern);
        $filteredVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $filtered
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'RegexIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $filteredVar, Variable::TYPE_HASHTABLE);

        return self::voidResult($context);
    }

    private static function filterMatchKeyed(Context $context, Value $srcHt, Value $pattern): Value
    {
        $tag = (string) (++self::$seq);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $dest = HashTableHelper::alloc($context);
        $written = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'regex_it_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'regex_it_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'regex_it_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $entry = JitValueBox::valuePtrFromVariable($context, $valVar);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $pregBlock = BasicBlockHelper::append($context, 'regex_it_preg_'.$tag);
        $advance = BasicBlockHelper::append($context, 'regex_it_adv_'.$tag);
        $context->builder->branchIf($isString, $pregBlock, $advance);

        $context->builder->positionAtEnd($pregBlock);
        $subject = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
        $matchRc = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match'),
            $pattern,
            $subject
        );
        $matched = $context->builder->icmp(
            Builder::INT_EQ,
            $matchRc,
            $i64->constInt(1, false)
        );
        $keep = BasicBlockHelper::append($context, 'regex_it_keep_'.$tag);
        $context->builder->branchIf($matched, $keep, $advance);

        $context->builder->positionAtEnd($keep);
        HashTableSliceLlvm::writeKeyed($context, $dest, $written, $keyVar, $valVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        HashTableSliceLlvm::markUnwrittenNullHolesUndefined(
            $context,
            $dest,
            $written,
            $i1->constInt(1, false)
        );

        return $dest;
    }

    private static function loadString(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException(
            'RegexIterator::__construct() pattern must be string, got '
            .Variable::getStringType($arg->type)
        );
    }

    private static function objectReceiver(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException(
            'RegexIterator::__construct() expects an object, got '
            .Variable::getStringType($receiver->type)
        );
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
