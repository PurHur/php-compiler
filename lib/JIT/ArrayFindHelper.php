<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\standard\JitArrayElem;
use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayFindRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for array_find / array_find_key / array_any / array_all (issue #3073).
 */
final class ArrayFindHelper
{
    private const MODE_FIND = 0;
    private const MODE_FIND_KEY = 1;
    private const MODE_ANY = 2;
    private const MODE_ALL = 3;

    public static function buildFindArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_FIND,
            $strictI1
        );
    }

    public static function buildFindKeyArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_FIND_KEY,
            $strictI1
        );
    }

    public static function buildAnyArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null,
        bool $unaryInternalUsesKey = false,
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_ANY,
            $strictI1,
            $unaryInternalUsesKey
        );
    }

    public static function buildAllArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $strictArg = null,
        ?Value $strictI1Override = null,
        bool $unaryInternalUsesKey = false,
    ): Value {
        $strictI1 = $strictI1Override ?? self::resolveStrictI1($context, $strictArg);

        return self::buildFromArray(
            $context,
            $array,
            $callback,
            self::MODE_ALL,
            $strictI1,
            $unaryInternalUsesKey
        );
    }

    /**
     * array_all()/array_any() on compile-time empty inline [] — vacuous true/false without callback (#11729).
     */
    public static function vacuousAnyAllIfCompileTimeEmpty(Context $context, Variable $array, bool $all): ?Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type) && 0 === $array->nextFreeElement) {
            return $context->constantFromBool($all);
        }
        if ($array->compileTimeEmptyArrayLiteral) {
            return $context->constantFromBool($all);
        }

        return null;
    }

    private static function buildFromArray(
        Context $context,
        Variable $array,
        Variable $callback,
        int $mode,
        ?Value $strictI1 = null,
        bool $unaryInternalUsesKey = false,
    ): Value {
        $strictI1 ??= $context->constantFromBool(false);
        if (ArrayFindCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayFindRuntime::walkClosure($context, $array, $callback, $mode, $strictI1);
        }
        $keyFirst = self::callbackKeyFirstForMode($mode, $unaryInternalUsesKey);
        JitArrayElem::requireArrayArg($context, $array, self::functionNameForMode($mode));
        if (self::MODE_ANY === $mode || self::MODE_ALL === $mode) {
            $vacuous = self::vacuousAnyAllIfCompileTimeEmpty($context, $array, self::MODE_ALL === $mode);
            if (null !== $vacuous) {
                return $vacuous;
            }
        }
        if (self::MODE_FIND === $mode || self::MODE_FIND_KEY === $mode) {
            self::requireNonEmptyFindArray($context, $array, self::functionNameForMode($mode));
        }
        $function = self::functionNameForMode($mode);
        if ($callback->isNullConstant) {
            throw new \TypeError(ArrayFindCallbackPolicy::invalidCallbackTypeError($function));
        }
        if (!ArrayFindCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            return self::buildFromNativeArray($context, $array, $callback, $mode, $strictI1, $unaryInternalUsesKey, $keyFirst);
        }

        return self::buildFromHashTable(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $array),
            $callback,
            $mode,
            $strictI1,
            $unaryInternalUsesKey,
            $keyFirst
        );
    }

    private static function callbackKeyFirstForMode(int $mode, bool $unaryInternalUsesKey): bool
    {
        return $unaryInternalUsesKey;
    }

    private static function buildFromNativeArray(
        Context $context,
        Variable $array,
        Variable $callback,
        int $mode,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
        bool $keyFirst = false,
    ): Value {
        $handler = self::resolvePredicateHandler($context, $callback);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $boolSlot = null;
        $resultSlot = null;
        if (self::MODE_ANY === $mode || self::MODE_ALL === $mode) {
            $boolSlot = $context->builder->alloca($i1, 1, 'array_find_native_bool');
            self::initBoolResultForMode($context, $boolSlot, $mode);
        } else {
            $resultSlot = JitValueBox::alloc($context);
            self::initValueResultForMode($context, $resultSlot);
        }

        $done = BasicBlockHelper::append($context, 'array_find_native_done');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_find_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_find_native_head');
        $body = BasicBlockHelper::append($context, 'array_find_native_body');
        $match = BasicBlockHelper::append($context, 'array_find_native_match');
        $advance = BasicBlockHelper::append($context, 'array_find_native_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        if (Variable::TYPE_STRING === $elemType) {
            $elem = new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        } else {
            $elem = new Variable(
                $context,
                $elemType,
                Variable::KIND_VALUE,
                $context->builder->load($slot)
            );
        }
        $keyVar = self::indexToKeyVariable($context, $idx);
        $truthy = self::invokePredicateMatch($context, $handler, $elem, $keyVar, $strictI1, $unaryInternalUsesKey, $keyFirst);
        $shouldStop = self::stopOnPredicate($context, $truthy, $mode);
        $context->builder->branchIf($shouldStop, $match, $advance);

        $context->builder->positionAtEnd($match);
        if (self::MODE_FIND === $mode) {
            self::storeNativeElemInValueBox($context, $resultSlot, $elem, $elemType);
        } elseif (self::MODE_FIND_KEY === $mode) {
            $i64 = $context->getTypeFromString('int64');
            JitValueBox::writeLong(
                $context,
                $resultSlot,
                $context->builder->truncOrBitCast($idx, $i64)
            );
        } elseif (self::MODE_ANY === $mode) {
            $context->builder->store($i1->constInt(1, false), $boolSlot);
        } else {
            $context->builder->store($i1->constInt(0, false), $boolSlot);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return self::finishResult($context, $mode, $resultSlot, $boolSlot);
    }

    private static function buildFromHashTable(
        Context $context,
        Value $ht,
        Variable $callback,
        int $mode,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
        bool $keyFirst = false,
    ): Value {
        $handler = self::resolvePredicateHandler($context, $callback);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $boolSlot = null;
        $resultSlot = null;
        if (self::MODE_ANY === $mode || self::MODE_ALL === $mode) {
            $boolSlot = $context->builder->alloca($i1, 1, 'array_find_ht_bool');
            self::initBoolResultForMode($context, $boolSlot, $mode);
        } else {
            $resultSlot = JitValueBox::alloc($context);
            self::initValueResultForMode($context, $resultSlot);
        }
        $done = BasicBlockHelper::append($context, 'array_find_ht_done');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_find_packed_idx');
        $context->builder->store($zero, $idxSlot);
        $packedHead = BasicBlockHelper::append($context, 'array_find_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_find_packed_body');
        $packedMatch = BasicBlockHelper::append($context, 'array_find_packed_match');
        $packedNext = BasicBlockHelper::append($context, 'array_find_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_find_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $packedCheck = BasicBlockHelper::append($context, 'array_find_packed_check');
        $context->builder->branchIf($isSet, $packedCheck, $packedNext);

        $context->builder->positionAtEnd($packedCheck);
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $keyVar = self::indexToKeyVariable($context, $idx);
        $truthy = self::invokePredicateMatch($context, $handler, $elem, $keyVar, $strictI1, $unaryInternalUsesKey, $keyFirst);
        $shouldStop = self::stopOnPredicate($context, $truthy, $mode);
        $context->builder->branchIf($shouldStop, $packedMatch, $packedNext);

        $context->builder->positionAtEnd($packedMatch);
        if (self::MODE_FIND === $mode) {
            JitValueBox::copyFromPointer($context, $resultSlot, JitValueBox::valuePtrFromVariable($context, $elem));
        } elseif (self::MODE_FIND_KEY === $mode) {
            $i64 = $context->getTypeFromString('int64');
            JitValueBox::writeLong(
                $context,
                $resultSlot,
                $context->builder->truncOrBitCast($idx, $i64)
            );
        } elseif (self::MODE_ANY === $mode) {
            $context->builder->store($i1->constInt(1, false), $boolSlot);
        } else {
            $context->builder->store($i1->constInt(0, false), $boolSlot);
        }
        $context->builder->branch($done);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedDone);
        self::buildStringKeyPredicateLoop(
            $context,
            $ht,
            $handler,
            $mode,
            $resultSlot,
            $boolSlot,
            $done,
            $strictI1,
            $unaryInternalUsesKey,
            $keyFirst
        );

        $context->builder->positionAtEnd($done);
        $retBlock = BasicBlockHelper::append($context, 'array_find_ht_return');
        $context->builder->branch($retBlock);
        $context->builder->positionAtEnd($retBlock);

        return self::finishResult($context, $mode, $resultSlot, $boolSlot);
    }

    private static function buildStringKeyPredicateLoop(
        Context $context,
        Value $ht,
        array $handler,
        int $mode,
        ?Value $resultSlot,
        ?Value $boolSlot,
        BasicBlock $doneBlock,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
        bool $keyFirst = false,
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $ptrSize = $sizeT->constInt(8, false);

        $strInit = BasicBlockHelper::append($context, 'array_find_str_init');
        $context->builder->branch($strInit);
        $context->builder->positionAtEnd($strInit);

        $strCountSlot = $context->builder->alloca($sizeT, 1, 'array_find_str_count');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'array_find_str_nodes');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_find_str_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);

        $countHead = BasicBlockHelper::append($context, 'array_find_str_count_head');
        $countBody = BasicBlockHelper::append($context, 'array_find_str_count_body');
        $countDone = BasicBlockHelper::append($context, 'array_find_str_count_done');
        $context->builder->branch($countHead);

        $context->builder->positionAtEnd($countHead);
        $walkNode = $context->builder->load($walkSlot);
        $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($walkEnd, $countDone, $countBody);

        $context->builder->positionAtEnd($countBody);
        $strCount = $context->builder->load($strCountSlot);
        $context->builder->store($context->builder->addNoSignedWrap($strCount, $one), $strCountSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($countHead);

        $context->builder->positionAtEnd($countDone);
        $numStrKeys = $context->builder->load($strCountSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $numStrKeys, $zero);
        $strEmpty = BasicBlockHelper::append($context, 'array_find_str_empty');
        $strWork = BasicBlockHelper::append($context, 'array_find_str_work');
        $context->builder->branchIf($isEmpty, $strEmpty, $strWork);

        $context->builder->positionAtEnd($strEmpty);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strWork);
        $bytes = $context->builder->mulNoSignedWrap($numStrKeys, $ptrSize);
        $nodesRaw = $context->builder->call($context->lookupFunction('malloc'), $bytes);
        $nodesArray = $context->builder->pointerCast($nodesRaw, $nodePtrType->pointerType(0));
        $context->builder->store($nodesArray, $nodesSlot);
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);

        $fillHead = BasicBlockHelper::append($context, 'array_find_str_fill_head');
        $fillBody = BasicBlockHelper::append($context, 'array_find_str_fill_body');
        $fillDone = BasicBlockHelper::append($context, 'array_find_str_fill_done');
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);

        $context->builder->positionAtEnd($fillBody);
        $fillIdx = $context->builder->load($strCountSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $context->builder->store($fillNode, $context->builder->inBoundsGEP($nodesArray, $fillIdx));
        $context->builder->store($context->builder->addNoSignedWrap($fillIdx, $one), $strCountSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillDone);
        $strIdxSlot = $context->builder->alloca($sizeT, 1, 'array_find_str_idx');
        $context->builder->store($zero, $strIdxSlot);
        $strHead = BasicBlockHelper::append($context, 'array_find_str_head');
        $strBody = BasicBlockHelper::append($context, 'array_find_str_body');
        $strMatch = BasicBlockHelper::append($context, 'array_find_str_match');
        $strNext = BasicBlockHelper::append($context, 'array_find_str_next');
        $strDrain = BasicBlockHelper::append($context, 'array_find_str_drain');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $nodeIdx = $context->builder->load($strIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $nodeIdx, $numStrKeys);
        $context->builder->branchIf($atEnd, $strDrain, $strBody);

        $context->builder->positionAtEnd($strBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $node = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $nodeIdx));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $elemSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $elemSlot, $valEntry);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $elemSlot);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = self::separatedStringToKeyVariable($context, $keyStr);
        $truthy = self::invokePredicateMatch($context, $handler, $elem, $keyVar, $strictI1, $unaryInternalUsesKey, $keyFirst);
        $shouldStop = self::stopOnPredicate($context, $truthy, $mode);
        $context->builder->branchIf($shouldStop, $strMatch, $strNext);

        $context->builder->positionAtEnd($strMatch);
        if (self::MODE_FIND === $mode) {
            JitValueBox::copyFromPointer($context, $resultSlot, $valEntry);
        } elseif (self::MODE_FIND_KEY === $mode) {
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $resultSlot),
                $owned
            );
        } elseif (self::MODE_ANY === $mode) {
            $context->builder->store($i1->constInt(1, false), $boolSlot);
        } else {
            $context->builder->store($i1->constInt(0, false), $boolSlot);
        }
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strNext);
        $context->builder->store($context->builder->addNoSignedWrap($nodeIdx, $one), $strIdxSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDrain);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($doneBlock);
    }

    private static function initValueResultForMode(Context $context, Value $resultSlot): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );
    }

    private static function initBoolResultForMode(Context $context, Value $boolSlot, int $mode): void
    {
        $i1 = $context->getTypeFromString('int1');
        $initial = self::MODE_ALL === $mode ? $i1->constInt(1, false) : $i1->constInt(0, false);
        $context->builder->store($initial, $boolSlot);
    }

    private static function finishResult(
        Context $context,
        int $mode,
        ?Value $resultSlot,
        ?Value $boolSlot
    ): Value {
        if (null !== $boolSlot) {
            return $context->builder->load($boolSlot);
        }

        return JitValueBox::pointer($context, $resultSlot);
    }

    /** @return array{0: string, 1: Call|Internal} */
    private static function resolvePredicateHandler(Context $context, Variable $callback): array
    {
        $name = $callback->compileTimeString;
        if (null !== $name) {
            try {
                return ['builtin', VmInternalCall::resolveStringCallback($name)];
            } catch (\LogicException) {
                // Not a registered internal — fall through to user-function proxy.
            }
        }
        if (ArrayReduceCallbackPolicy::isJitLowerable($callback)) {
            $proxy = ArrayBuiltinHelper::resolveReduceCallbackForFind($context, $callback);

            return ['user', $proxy];
        }

        return ['builtin', ArrayBuiltinHelper::resolveMapCallbackForFind($callback)];
    }

    private static function invokePredicateMatch(
        Context $context,
        array $handler,
        Variable $elem,
        Variable $key,
        Value $strictI1,
        bool $unaryInternalUsesKey = false,
        bool $keyFirst = false,
    ): Value {
        [$kind, $target] = $handler;
        if ('builtin' === $kind) {
            /** @var Internal $target */
            $mapped = self::invokeBuiltinPredicate($context, $target, $elem, $key, $unaryInternalUsesKey, $keyFirst);

            return self::jitCallResultMatch($context, $mapped, $strictI1);
        }
        /** @var Call $target */
        if ($keyFirst) {
            $result = $target->call($context, $key, $elem);
        } else {
            $result = $target->call($context, $elem, $key);
        }

        return self::jitCallResultMatch(
            $context,
            self::normalizeUserPredicateResult($context, $result),
            $strictI1
        );
    }

    private static function invokeBuiltinPredicate(
        Context $context,
        Internal $target,
        Variable $elem,
        Variable $key,
        bool $unaryInternalUsesKey,
        bool $keyFirst = false,
    ): Value {
        $maxArgs = \PHPCompiler\ext\standard\InternalArityPolicy::maxArgsForArrayCallback($target);
        if ($maxArgs <= 1) {
            return $target->call($context, $unaryInternalUsesKey ? $key : $elem);
        }
        if ($keyFirst) {
            return $target->call($context, $key, $elem);
        }

        return $target->call($context, $elem, $key);
    }

    /**
     * Standalone AOT may return native int1/int64 for int-backed user callbacks; box for strict (#15704).
     */
    private static function normalizeUserPredicateResult(Context $context, Value $result): Value
    {
        $ty = $context->getStringFromType($result->typeOf());
        if ('__value__' === $ty || '__value__*' === $ty || 'int1' === $ty || 'bool' === $ty || 'int64' === $ty || 'long long' === $ty) {
            return $result;
        }

        return $result;
    }

    private static function resolveStrictI1(Context $context, ?Variable $strictArg): Value
    {
        if (null === $strictArg) {
            return $context->constantFromBool(false);
        }

        return JitBoolArg::lower($context, $strictArg, 'array_all_key() strict');
    }

    private static function jitCallResultMatch(Context $context, Value $result, Value $strictI1): Value
    {
        $truthy = self::jitCallResultTruthy($context, $result);
        $strictTrue = self::jitCallResultStrictTrue($context, $result);

        return $context->builder->select($strictI1, $strictTrue, $truthy);
    }

    private static function jitCallResultStrictTrue(Context $context, Value $result): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $false = $i1->constInt(0, false);
        $ty = $context->getStringFromType($result->typeOf());
        if ('int1' === $ty) {
            // Native int1 is not a boxed boolean; int 0/1 ternaries mis-lower to int1 in standalone AOT (#15704).
            return $false;
        }
        if ('int64' === $ty || 'long long' === $ty || 'double' === $ty) {
            return $false;
        }
        if ('__value__' === $ty) {
            $slot = BasicBlockHelper::entryAlloca($context, $result->typeOf());
            $context->builder->store($result, $slot);
            $valuePtr = JitValueBox::pointer($context, $slot);
        } elseif ('__value__*' === $ty) {
            $valuePtr = $result;
        } else {
            return $false;
        }
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $valueMap['type']));
        $isBool = boolval::isBoxedBoolTypeTag($context, $typeByte);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $longVal, $zero);

        return $context->builder->and($isBool, $isTrue);
    }

    private static function indexToKeyVariable(Context $context, Value $idx): Variable
    {
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($idx, $i64)
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    private static function separatedStringToKeyVariable(Context $context, Value $keyStr): Variable
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /**
     * Predicate truthiness for boxed callback results (standalone AOT mixed returns; #15704).
     *
     * Avoids boolval() JIT which treats any non-null boxed type as true in user-script AOT.
     */
    private static function jitBoxedPredicateTruthy(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $false = $context->constantFromBool(false);
        $falsy = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_NULL, false)),
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_UNDEFINED, false))
        );

        $isBool = boolval::isBoxedBoolTypeTag($context, $typeByte);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolTruthy = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($firstByte),
            $i8->constInt(0, false)
        );

        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_INTEGER, false)
        );
        $zeroI64 = $context->getTypeFromString('int64')->constInt(0, false);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $intTruthy = $context->builder->icmp(Builder::INT_NE, $longVal, $zeroI64);

        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_FLOAT, false)
        );
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $floatTruthy = $context->builder->fcmp(
            Builder::REAL_ONE,
            $doubleVal,
            $doubleVal->typeOf()->constReal(0.0)
        );

        $defaultTruthy = $context->constantFromBool(true);
        $typedTruthy = $context->builder->select(
            $isBool,
            $boolTruthy,
            $context->builder->select(
                $isInt,
                $intTruthy,
                $context->builder->select($isFloat, $floatTruthy, $defaultTruthy)
            )
        );

        return $context->builder->select($falsy, $false, $typedTruthy);
    }

    private static function jitCallResultTruthy(Context $context, Value $result): Value
    {
        $ty = $context->getStringFromType($result->typeOf());
        if ('int1' === $ty) {
            return $result;
        }
        if ('int64' === $ty || 'long long' === $ty) {
            $zero = $result->typeOf()->constInt(0, false);

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }
        if ('double' === $ty) {
            $zero = $result->typeOf()->constReal(0.0);

            return $context->builder->fcmp(Builder::REAL_ONE, $result, $zero);
        }
        if ('__value__' === $ty) {
            $slot = BasicBlockHelper::entryAlloca($context, $result->typeOf());
            $context->builder->store($result, $slot);

            return self::jitBoxedPredicateTruthy($context, JitValueBox::pointer($context, $slot));
        }
        if ('__value__*' === $ty) {
            return self::jitBoxedPredicateTruthy($context, $result);
        }

        throw new \LogicException(
            'array_find() predicate return type not supported for JIT in this build: '.$ty
        );
    }

    private static function stopOnPredicate(Context $context, Value $truthy, int $mode): Value
    {
        if (self::MODE_ALL === $mode) {
            return $context->builder->not($truthy);
        }

        return $truthy;
    }

    private static function storeNativeElemInValueBox(
        Context $context,
        Value $resultSlot,
        Variable $elem,
        int $elemType
    ): void {
        if (Variable::TYPE_STRING === $elemType) {
            $str = $context->helper->loadValue($elem);
            $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $resultSlot),
                $owned
            );

            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $elemType) {
            JitValueBox::writeLong($context, $resultSlot, $context->helper->loadValue($elem));

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $resultSlot),
                $context->helper->loadValue($elem)
            );

            return;
        }
        if (Variable::TYPE_NATIVE_BOOL === $elemType) {
            JitValueBox::writeBool($context, $resultSlot, $context->helper->loadValue($elem));

            return;
        }
        throw new \LogicException('array_find() native element type not supported for JIT');
    }

    private static function functionNameForMode(int $mode): string
    {
        return match ($mode) {
            self::MODE_FIND => 'array_find',
            self::MODE_FIND_KEY => 'array_find_key',
            self::MODE_ANY => 'array_any',
            self::MODE_ALL => 'array_all',
            default => 'array_find',
        };
    }

    private static function requireNonEmptyFindArray(Context $context, Variable $array, string $fn): void
    {
        $message = \sprintf('%s(): Argument #1 ($array) must not be empty', $fn);
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            $sizeT = $context->getTypeFromString('size_t');
            $zero = $sizeT->constInt(0, false);
            $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
            TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
                $context,
                $context->builder->not($isEmpty),
                'array_find_nonempty',
                $message
            );

            return;
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($isEmpty),
            'array_find_ht_nonempty',
            $message
        );
    }
}
