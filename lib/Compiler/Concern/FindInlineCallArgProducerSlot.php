<?php

namespace PHPCompiler\Compiler\Concern;

/**
 * Inline call-arg producer slot discovery (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Array_ producer discovery lives in {@see FindInlineArrayProducerForCallArg}.
 * Inline expr producer discovery lives in {@see FindInlineExprCallArgProducerSlot}.
 * Coalesce / nullsafe call-arg slots live in {@see FindInlineCoalesceAndNullsafeCallArgSlots}.
 * Dead-temp / haystack-family helpers live in
 * {@see DeadTempInlineArrayAndHaystackCallArgHelpers}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait FindInlineCallArgProducerSlot
{
}
