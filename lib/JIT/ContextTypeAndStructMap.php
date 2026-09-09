<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Type/struct map hub for {@see Context} (#36387).
 *
 * Methods formerly here were split out:
 * - PHPTypes→LLVM: {@see ContextTypeFromPhpType}
 * - String→LLVM: {@see ContextTypeFromString}
 * - Bool cast: {@see ContextCastToBool}
 * - Struct field maps: {@see ContextStructFieldMap}
 *
 * Kept as an empty composition marker so existing `use ContextTypeAndStructMap`
 * wiring and spine inventory stay stable while the size-budget ratchet continues.
 *
 * No new C ABI. php-src analogy: type conversion helpers live beside the
 * executor (Zend/zend_types.h, Zend/zend_operators.c).
 */
trait ContextTypeAndStructMap
{
}
