<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime `static::method()` dispatch by late-static class id (AOT / standalone) (#24169).
 *
 * Compile-time resolution of the `static` keyword to the declaring class treats
 * `static::` as `self::`. php-src looks up the method from get_called_scope().
 *
 * @see \PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall
 * @see \PHPCompiler\ext\standard\JitForwardStaticCall
 * php-src: Zend/zend_execute.c — ZEND_INIT_STATIC_METHOD_CALL / get_called_scope
 */
final class RuntimeIndirectStaticMethodCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param array<int, Call> $candidatesByClassId class id => lowered static method proxy
     * @param bool             $bindCallerThis      Prepend enclosing $this for non-static
     *                                              candidates (static:: from instance, #28050)
     */
    public function __construct(
        public readonly string $methodLc,
        public readonly array $candidatesByClassId,
        public readonly Block $enclosingBlock,
        public readonly bool $bindCallerThis = false,
        public readonly ?Value $runtimeClassId = null,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $this->candidatesByClassId) {
            throw new \LogicException('RuntimeIndirectStaticMethodCall requires at least one candidate');
        }

        // Same LSB class-id path as static::CONST / static::class — must not call
        // emitEffectiveLateStaticClassId here: ensureLinked clears the insert block (#19614).
        $classId = $this->runtimeClassId ?? ClassConstFetchHelper::emitStaticKeywordClassIdForPseudoConst(
            $context->type->object,
            $this->enclosingBlock
        );

        return $this->dispatchByClassId($context, $classId, ...$args);
    }

    private function dispatchByClassId(Context $context, Value $classId, Variable ...$args): Value
    {
        $tag = 'sm'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'indirect_static_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'indirect_static_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $zero = $context->getTypeFromString('__value__*')->constNull();
        $context->builder->store($zero, $resultSlot);

        $ids = array_keys($this->candidatesByClassId);
        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'indirect_static_check_'.$tag.'_'.$i);
        }

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $expected = $context->constantFromInteger($id, 'int64');
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $onMatch = BasicBlockHelper::append($context, 'indirect_static_match_'.$tag.'_'.$i);
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
            $raw = $proxy->call($context, ...$args);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
