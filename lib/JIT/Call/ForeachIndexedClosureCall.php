<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Invoke the closure proxy for the current foreach iteration (#34240 peer).
 *
 * Compile-time ClosureWithCaptures on the iter value local is wrong — ITER_VALUE
 * is lowered once but runs every iteration. Select the proxy at runtime from the
 * literal build-order table using the foreach index slot (after packedHead +1).
 */
final class ForeachIndexedClosureCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param list<Call> $proxies Literal array element order
     */
    public function __construct(
        public readonly Variable $callee,
        public readonly array $proxies,
        public readonly string $containerSlotKey,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $indexSlot = $context->foreachIndexSlots[$this->containerSlotKey] ?? null;
        if (null === $indexSlot) {
            $context->builder->call($context->lookupFunction('abort'));

            return $context->getTypeFromString('__value__*')->constNull();
        }

        $sizeT = $context->getTypeFromString('size_t');
        // IteratorHelper::compileValid advances index before ITER_VALUE / body.
        $idx = $context->builder->load($indexSlot);

        $n = \count($this->proxies);
        if (0 === $n) {
            $context->builder->call($context->lookupFunction('abort'));

            return $context->getTypeFromString('__value__*')->constNull();
        }
        if (1 === $n) {
            return $this->proxies[0]->call($context, ...$args);
        }

        $tag = 'fec'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'foreach_closure_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'foreach_closure_undef_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $null = $context->getTypeFromString('__value__*')->constNull();

        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'foreach_closure_check_'.$tag.'_'.$i);
        }

        $i = 0;
        foreach ($this->proxies as $proxy) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $lit = $sizeT->constInt($i, false);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $idx, $lit);
            $onMatch = BasicBlockHelper::append($context, 'foreach_closure_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = $proxy->call($context, ...$args);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
            ++$i;
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->store($null, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
