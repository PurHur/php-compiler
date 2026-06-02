<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helpers for array_first() / array_last() (#3491). */
final class JitArrayElem
{
    private const TYPE_ERROR = '%s(): Argument #1 ($array) must be of type array, %s given';

    private const EMPTY_VALUE_ERROR = '%s(): Argument #1 ($array) must not be empty';

    public static function first(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'array_first');

        return self::elemAtEnd($context, $array, true, 'array_first');
    }

    public static function last(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'array_last');

        return self::elemAtEnd($context, $array, false, 'array_last');
    }

    private static function elemAtEnd(Context $context, JITVariable $array, bool $first, string $fn): Value
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);

        $emptyBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_empty');
        $workBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_work');
        $doneBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        self::emitErrorAndAbort($context, \sprintf(self::EMPTY_VALUE_ERROR, $fn));

        $context->builder->positionAtEnd($workBb);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $packedBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_packed');
        $stringBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_string');
        $context->builder->branchIf($hasPacked, $packedBb, $stringBb);

        $tag = $first ? 'first' : 'last';
        $context->builder->positionAtEnd($packedBb);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_elem_'.$tag.'_idx');
        if ($first) {
            $context->builder->store($zero, $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($nextFree, $one), $idxSlot);
        }
        $loopHead = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_head');
        $loopBody = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_body');
        $loopFound = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_found');
        $loopNext = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_next');
        $loopFail = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        if ($first) {
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
            $context->builder->branchIf($atEnd, $loopFail, $loopBody);
        } else {
            $atStart = $context->builder->icmp(Builder::INT_EQ, $idx, $zero);
            $context->builder->branchIf($atStart, $loopFail, $loopBody);
        }

        $context->builder->positionAtEnd($loopBody);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($present, $loopFound, $loopNext);

        $context->builder->positionAtEnd($loopFound);
        $entryPtr = HashTableHelper::listEntryPointer($context, $ht, $idx);
        JitValueBox::copyFromPointer($context, $resultPtr, $entryPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopNext);
        if ($first) {
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        }
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopFail);
        self::emitErrorAndAbort($context, \sprintf(self::EMPTY_VALUE_ERROR, $fn));

        $context->builder->positionAtEnd($stringBb);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        if ($first) {
            $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_elem_first_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_elem_first_str_found');
            $context->builder->branchIf($headNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            self::emitErrorAndAbort($context, \sprintf(self::EMPTY_VALUE_ERROR, $fn));
            $context->builder->positionAtEnd($strFound);
            $valEntry = $context->builder->structGep($head, $nodeMap['value']);
            JitValueBox::copyFromPointer($context, $resultPtr, $valEntry);
            $context->builder->branch($doneBb);
        } else {
            $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_elem_last_walk');
            $lastSlot = $context->builder->alloca($nodePtrType, 1, 'array_elem_last_node');
            $context->builder->store($head, $walkSlot);
            $context->builder->store($nodePtrType->constNull(), $lastSlot);
            $walkHead = BasicBlockHelper::append($context, 'array_elem_last_walk_head');
            $walkBody = BasicBlockHelper::append($context, 'array_elem_last_walk_body');
            $walkDone = BasicBlockHelper::append($context, 'array_elem_last_walk_done');
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkHead);
            $walkNode = $context->builder->load($walkSlot);
            $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
            $context->builder->branchIf($walkEnd, $walkDone, $walkBody);

            $context->builder->positionAtEnd($walkBody);
            $context->builder->store($walkNode, $lastSlot);
            $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
            $context->builder->store($nextWalk, $walkSlot);
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkDone);
            $lastNode = $context->builder->load($lastSlot);
            $lastNull = $context->builder->icmp(Builder::INT_EQ, $lastNode, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_elem_last_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_elem_last_str_found');
            $context->builder->branchIf($lastNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            self::emitErrorAndAbort($context, \sprintf(self::EMPTY_VALUE_ERROR, $fn));
            $context->builder->positionAtEnd($strFound);
            $valEntry = $context->builder->structGep($lastNode, $nodeMap['value']);
            JitValueBox::copyFromPointer($context, $resultPtr, $valEntry);
            $context->builder->branch($doneBb);
        }

        $context->builder->positionAtEnd($doneBb);

        return $resultPtr;
    }

    public static function requireArrayArg(Context $context, JITVariable $array, string $fn): void
    {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $array->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $array);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($loaded, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $isHt = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            );
            $okBlock = BasicBlockHelper::append($context, 'array_elem_req_ok');
            $errBlock = BasicBlockHelper::append($context, 'array_elem_req_err');
            $context->builder->branchIf($isHt, $okBlock, $errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, 'mixed'));
            $context->builder->positionAtEnd($okBlock);

            return;
        }
        $okBlock = BasicBlockHelper::append($context, 'array_req_ok');
        $errBlock = BasicBlockHelper::append($context, 'array_req_err');
        $context->builder->branch($errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitErrorAndAbort($context, \sprintf(self::TYPE_ERROR, $fn, self::jitTypeLabel($array->type)));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeLabel(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
