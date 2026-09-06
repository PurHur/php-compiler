<?php

namespace PHPCompiler\Compiler\Concern;

/**
 * Specialized inline call-arg ARG_SEND compilers hub (#36387 / #36403).
 *
 * Hollowed for gen-0 split-TU: array_pad / unpack / extract / date_sun_* live in
 * {@see ArrayPadUnpackExtractAndDateSunCallArgSends}; explode / iterator_to_array /
 * array_chunk / array_walk / trailing-comparator live in
 * {@see ExplodeIteratorChunkWalkAndTrailingComparatorCallArgSends}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait CompileInlineSpecializedCallArgSends
{
}
