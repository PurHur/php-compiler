<?php

namespace PHPCompiler\Compiler\Concern;

/**
 * Sibling / multi-arg inline FuncCall producer discovery hub (#36387 / #36403).
 *
 * Methods live in:
 * - {@see HoistedMultiArgSiblingFuncCallChain} (hoisted multi-arg chain helpers)
 * - {@see EnsureDeferredSiblingAndInlineNewProducers} (New_/deferred compile)
 * - {@see SiblingMultiArgFuncCallProducerDetect} (multi-arg detect/ordinal)
 * - {@see FirstSiblingInlineFuncCallProducerIndex} (first-sibling index scan/cache)
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait SiblingInlineFuncCallProducers
{
}
