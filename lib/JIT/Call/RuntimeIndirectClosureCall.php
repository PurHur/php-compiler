<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime invoke for closures loaded from array elements / properties (issue #72).
 */
final class RuntimeIndirectClosureCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param array<string, Call> $candidates Lowercase internal name => invoke proxy
     */
    public function __construct(
        public readonly Variable $callee,
        public readonly array $candidates,
        public readonly int $closureClassId,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $obj = $this->loadCallableObject($context);
        $classId = ClosureHelper::loadClassId($context, $obj);
        $isClosure = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->constantFromInteger($this->closureClassId, 'int64')
        );
        $failBlock = BasicBlockHelper::append($context, 'closure_indirect_fail');
        $okBlock = BasicBlockHelper::append($context, 'closure_indirect_ok');
        $context->builder->branchIf($isClosure, $okBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);

        $targetVar = $context->type->object->propertyFetch(
            $obj,
            'Closure',
            ClosureHelper::TARGET_PROPERTY
        );
        $targetStr = JitStringArg::lower($context, $targetVar, 'closure invoke target');

        if (1 === \count($this->candidates)) {
            $name = array_key_first($this->candidates);
            assert(is_string($name));

            return $this->dispatchSingleCandidate($context, $targetStr, $name, $this->candidates[$name], ...$args);
        }

        return $this->dispatchCandidates($context, $targetStr, ...$args);
    }

    private function loadCallableObject(Context $context): Value
    {
        if (Variable::TYPE_OBJECT === $this->callee->type) {
            return ClosureHelper::loadObjectFromCallable($context, $this->callee);
        }
        if (Variable::TYPE_VALUE !== $this->callee->type) {
            throw new \LogicException('Indirect closure invoke requires object or value-box callee');
        }

        $valPtr = JitValueBox::valuePtrFromVariable($context, $this->callee);
        $typeByte = $context->builder->load(
            $context->builder->structGep(
                $valPtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $failBlock = BasicBlockHelper::append($context, 'closure_indirect_not_object');
        $okBlock = BasicBlockHelper::append($context, 'closure_indirect_is_object');
        $context->builder->branchIf($isObject, $okBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
    }

    /**
     * @param array<string, Call> $candidates
     */
    private function dispatchCandidates(Context $context, Value $targetStr, Variable ...$args): Value
    {
        $tag = 'cl'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'closure_indirect_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'closure_indirect_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $zero = $context->getTypeFromString('__value__*')->constNull();

        $n = \count($this->candidates);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'closure_indirect_check_'.$tag.'_'.$i);
        }

        $i = 0;
        foreach ($this->candidates as $fnName => $proxy) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));
            $isMatch = JitStringCompare::identical($context, $targetStr, $literalStr);
            $onMatch = BasicBlockHelper::append($context, 'closure_indirect_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = $proxy->call($context, ...$args);
            $context->builder->store($raw, $resultSlot);
            $context->builder->branch($merge);
            ++$i;
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    private function dispatchSingleCandidate(
        Context $context,
        Value $targetStr,
        string $fnName,
        Call $proxy,
        Variable ...$args
    ): Value {
        $tag = 'cl1'.(string) ++self::$blockSeq;
        $literalStr = $context->builder->load($context->constantStringFromString($fnName));
        $isMatch = JitStringCompare::identical($context, $targetStr, $literalStr);
        $matchBlock = BasicBlockHelper::append($context, 'closure_indirect_match_'.$tag);
        $failBlock = BasicBlockHelper::append($context, 'closure_indirect_nomatch_'.$tag);
        $context->builder->branchIf($isMatch, $matchBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($matchBlock);

        return $proxy->call($context, ...$args);
    }
}
