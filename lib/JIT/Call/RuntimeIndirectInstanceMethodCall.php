<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime instance method dispatch by __object__.class_id when PHPCfg cannot
 * infer a concrete class (e.g. method returns :object holding an anonymous class) (#3098).
 */
final class RuntimeIndirectInstanceMethodCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param array<int, Call> $candidatesByClassId class id => lowered method proxy
     */
    public function __construct(
        public readonly Variable $receiver,
        public readonly string $methodLc,
        public readonly array $candidatesByClassId,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $this->candidatesByClassId) {
            throw new \LogicException('RuntimeIndirectInstanceMethodCall requires at least one candidate');
        }

        $obj = $this->loadReceiverObject($context);
        $classId = ClosureHelper::loadClassId($context, $obj);

        return $this->dispatchByClassId($context, $obj, $classId, ...$args);
    }

    private function loadReceiverObject(Context $context): Value
    {
        if (Variable::TYPE_OBJECT === $this->receiver->type) {
            return ClosureHelper::loadObjectFromCallable($context, $this->receiver);
        }
        if (Variable::TYPE_VALUE !== $this->receiver->type) {
            throw new \LogicException(
                'RuntimeIndirectInstanceMethodCall requires object or value-box receiver'
            );
        }

        $valPtr = JitValueBox::valuePtrFromVariable($context, $this->receiver);
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
        $failBlock = BasicBlockHelper::append($context, 'indirect_method_not_object');
        $okBlock = BasicBlockHelper::append($context, 'indirect_method_is_object');
        $context->builder->branchIf($isObject, $okBlock, $failBlock);
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
    }

    private function dispatchByClassId(Context $context, Value $obj, Value $classId, Variable ...$args): Value
    {
        $tag = 'im'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'indirect_method_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'indirect_method_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $zero = $context->getTypeFromString('__value__*')->constNull();
        $thisVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $thisVar->addref();
        $callArgs = $args;
        if ([] === $callArgs) {
            $callArgs = [$thisVar];
        } else {
            $callArgs[0] = $thisVar;
        }

        $ids = array_keys($this->candidatesByClassId);
        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'indirect_method_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'indirect_method_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            if (LateStaticBindingHelper::useRuntimeLateStatic($context)) {
                LateStaticBindingHelper::emitStoreClassId(
                    $context,
                    $context->constantFromInteger($id, 'int64')
                );
            }
            $proxy = $this->candidatesByClassId[$id];
            assert($proxy instanceof Call);
            $raw = $proxy->call($context, ...$callArgs);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
