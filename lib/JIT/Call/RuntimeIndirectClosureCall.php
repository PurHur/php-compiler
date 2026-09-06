<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClosureBindHelper;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\VmClosure;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * Runtime invoke for closures loaded from array elements / properties (issue #72).
 *
 * IncludeHelper project graphs NestedJIT library units (Slim RequestResponse, etc.)
 * before the entry script's route closures exist. Snapshotting {@see VmClosure::closureCandidates()}
 * at that moment freezes a partial `{closure}_N` table and aborts when a later closure is
 * invoked (#36382). Defer the strcmp dispatch body until module seal so every proxy is visible.
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
        if ($context->materializingRuntimeIndirectClosureDispatch) {
            return $this->emitDispatchNow($context, ...$args);
        }

        return $this->emitDeferredDispatch($context, ...$args);
    }

    /**
     * Fill deferred `__phpc_ric_deferred_*` bodies with the full closure candidate set (#36382).
     */
    public static function materializePending(Context $context): void
    {
        $guard = 0;
        while ([] !== $context->pendingRuntimeIndirectClosureDispatches) {
            if (++$guard > 10000) {
                throw new \LogicException('RuntimeIndirectClosureCall materialize did not converge (#36382)');
            }
            $batch = $context->pendingRuntimeIndirectClosureDispatches;
            $context->pendingRuntimeIndirectClosureDispatches = [];
            $context->materializingRuntimeIndirectClosureDispatch = true;
            try {
                foreach ($batch as $item) {
                    self::materializeOne($context, $item);
                }
            } finally {
                $context->materializingRuntimeIndirectClosureDispatch = false;
            }
        }
    }

    /**
     * @param array{func: Function_, closureClassId: int, nargs: int} $item
     */
    private static function materializeOne(Context $context, array $item): void
    {
        $func = $item['func'];
        $entry = $func->getFirstBasicBlock();
        if (null === $entry) {
            $entry = $func->appendBasicBlock('entry');
        }
        if (null !== $entry->getTerminator()) {
            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $prevLowering = $context->loweringLlvmFunction;
        $context->loweringLlvmFunction = $func;
        $context->builder->positionAtEnd($entry);

        $objParam = $func->getParam(0);
        $callee = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objParam);
        $args = [];
        for ($i = 0; $i < $item['nargs']; ++$i) {
            $args[] = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $func->getParam($i + 1)
            );
        }

        $candidates = array_merge(
            VmClosure::closureCandidates($context),
            $context->fccCallableProxies
        );
        if ([] === $candidates) {
            // Fall back to the snapshot taken at the call site (may still be incomplete).
            $candidates = $item['candidates'] ?? [];
        }
        if ([] === $candidates) {
            $context->builder->call($context->lookupFunction('abort'));
            $nullSlot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $nullSlot)
            );
            $context->builder->returnValue($context->builder->load($nullSlot));
        } else {
            $ric = new self($callee, $candidates, $item['closureClassId']);
            $raw = $ric->emitDispatchNow($context, ...$args);
            // Copy into a local box then return by value — coerce may yield a __value__*
            // into a callee frame that is already dead (#36382).
            $local = JitValueBox::alloc($context);
            $localPtr = JitValueBox::pointer($context, $local);
            $tyName = $context->getStringFromType($raw->typeOf());
            if ('__value__' === $tyName) {
                $context->builder->store($raw, $local);
            } else {
                $ptr = JitValueBox::coerceToValuePtrForStore($context, $raw);
                $isNull = $context->builder->icmp(
                    Builder::INT_EQ,
                    $ptr,
                    $context->getTypeFromString('__value__*')->constNull()
                );
                $nullBb = BasicBlockHelper::append($context, 'ric_deferred_ret_null');
                $copyBb = BasicBlockHelper::append($context, 'ric_deferred_ret_copy');
                $doneBb = BasicBlockHelper::append($context, 'ric_deferred_ret_done');
                $context->builder->branchIf($isNull, $nullBb, $copyBb);
                $context->builder->positionAtEnd($nullBb);
                $context->builder->call($context->lookupFunction('__value__writeNull'), $localPtr);
                $context->builder->branch($doneBb);
                $context->builder->positionAtEnd($copyBb);
                $context->builder->call($context->lookupFunction('__value__copy'), $localPtr, $ptr);
                $context->builder->branch($doneBb);
                $context->builder->positionAtEnd($doneBb);
            }
            $context->builder->returnValue($context->builder->load($local));
        }

        $context->loweringLlvmFunction = $prevLowering;
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    private function emitDeferredDispatch(Context $context, Variable ...$args): Value
    {
        $obj = $this->loadCallableObject($context);
        $argPtrs = [];
        foreach ($args as $arg) {
            $argPtrs[] = self::argAsValuePtr($context, $arg);
        }

        $seq = (string) ++self::$blockSeq;
        $name = '__phpc_ric_deferred_'.$seq;
        // By-value __value__ — pointer returns into this frame would dangle at the call site (#36382).
        $retTy = $context->getTypeFromString('__value__');
        $objTy = $context->getTypeFromString('__object__*');
        $valpTy = $context->getTypeFromString('__value__*');
        $paramTys = array_merge([$objTy], array_fill(0, \count($args), $valpTy));
        $sig = $context->context->functionType($retTy, false, ...$paramTys);
        $func = $context->module->addFunction($name, $sig);
        $func->appendBasicBlock('entry');

        $context->pendingRuntimeIndirectClosureDispatches[] = [
            'func' => $func,
            'closureClassId' => $this->closureClassId,
            'nargs' => \count($args),
            // Snapshot kept only as last-resort if seal somehow has zero proxies.
            'candidates' => $this->candidates,
        ];

        return $context->builder->call($func, $obj, ...$argPtrs);
    }

    private function emitDispatchNow(Context $context, Variable ...$args): Value
    {
        // Allocas must be emitted while entry has no terminator — propertyFetch /
        // dispatchCandidates call entryAlloca after the class-id branch otherwise
        // splices into a sealed entry and fails module verify (#36382).
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->store(
            $context->getTypeFromString('__value__*')->constNull(),
            $resultSlot
        );

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
            $raw = $this->dispatchSingleCandidate(
                $context,
                $obj,
                $targetStr,
                $name,
                $this->candidates[$name],
                ...$args
            );
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );

            return $context->builder->load($resultSlot);
        }

        return $this->dispatchCandidatesInto($context, $obj, $targetStr, $resultSlot, ...$args);
    }

    /**
     * @param array<string, Call> $candidates
     */
    private function dispatchCandidatesInto(
        Context $context,
        Value $obj,
        Value $targetStr,
        Value $resultSlot,
        Variable ...$args
    ): Value {
        $tag = 'cl'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'closure_indirect_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'closure_indirect_undef_'.$tag);
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
            $raw = ClosureBindHelper::wrapCallWithBindingFromObject($context, $obj, $proxy, ...$args);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
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

    private function dispatchCandidates(Context $context, Value $obj, Value $targetStr, Variable ...$args): Value
    {
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));

        return $this->dispatchCandidatesInto($context, $obj, $targetStr, $resultSlot, ...$args);
    }

    private static function argAsValuePtr(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_VALUE === $arg->type && Variable::KIND_VALUE === $arg->kind) {
            $tyName = $context->getStringFromType($arg->value->typeOf());
            if ('__value__*' === $tyName) {
                return $arg->value;
            }
        }

        return JitValueBox::valuePtrFromVariable($context, $arg);
    }

    private function loadCallableObject(Context $context): Value
    {
        if (Variable::TYPE_OBJECT === $this->callee->type) {
            return ClosureHelper::loadObjectFromCallable($context, $this->callee);
        }
        if (Variable::TYPE_VALUE !== $this->callee->type) {
            throw new \LogicException('Indirect closure invoke requires object or value-box callee');
        }

        // KIND_VALUE `__value__*` formals — prefer VmClosure path (avoids null alloca, #36382).
        if (Variable::KIND_VALUE === $this->callee->kind) {
            return ClosureHelper::loadObjectFromCallable($context, $this->callee);
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

    private function dispatchSingleCandidate(
        Context $context,
        Value $obj,
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

        return ClosureBindHelper::wrapCallWithBindingFromObject($context, $obj, $proxy, ...$args);
    }
}
