<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime dispatch for `static::method()` on the late-static class id (#24169).
 *
 * A method body is lowered once and shared by every subclass, so the `static` keyword cannot be
 * resolved at compile time: `Child::make()` and `Base::make()` execute the same code. The call site
 * already stores the called class id ({@see \PHPCompiler\JIT\LateStaticBindingHelper::emitStoreClassId}),
 * so this loads it back and picks the override the called class actually resolves to.
 *
 * Sibling of {@see RuntimeIndirectInstanceMethodCall}, which dispatches on a receiver object's
 * class id. Three differences, all forced by there being no receiver:
 *  - the class id comes from the late-static global, not from an `__object__`;
 *  - args are passed through untouched (a static method takes no implicit `$this`);
 *  - an unknown id falls back to the declaring-class proxy rather than `abort()`, because the
 *    stored id is very often a subclass that does not override the method at all.
 */
final class RuntimeLateStaticMethodCall implements Call
{
    private static int $blockSeq = 0;

    /**
     * @param array<int, Call> $candidatesByClassId class id => proxy for the override it resolves to
     * @param Call             $fallback            declaring-class proxy, for ids not in the map
     */
    public function __construct(
        public readonly string $methodLc,
        public readonly array $candidatesByClassId,
        public readonly Call $fallback,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $this->candidatesByClassId) {
            return $this->fallback->call($context, ...$args);
        }
        $classId = LateStaticBindingHelper::emitLoadClassId($context);

        $tag = 'ls'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'late_static_merge_'.$tag);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));

        $ids = array_keys($this->candidatesByClassId);
        $n = \count($ids);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'late_static_check_'.$tag.'_'.$i);
        }
        $fallbackBlock = BasicBlockHelper::append($context, 'late_static_fallback_'.$tag);

        foreach ($ids as $i => $id) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $onMatch = BasicBlockHelper::append($context, 'late_static_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $fallbackBlock;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            // The called class is unchanged across a forwarding `static::` call, so the late-static
            // id is deliberately NOT re-stored here.
            $raw = $this->candidatesByClassId[$id]->call($context, ...$args);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $raw),
                $resultSlot
            );
            $context->builder->branch($merge);
        }

        $context->builder->positionAtEnd($fallbackBlock);
        $raw = $this->fallback->call($context, ...$args);
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $raw),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }
}
