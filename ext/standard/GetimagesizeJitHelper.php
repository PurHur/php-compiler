<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Deprecated NestedJIT surface for getimagesize*() (#3271).
 *
 * Thin AOT uses {@see GetimagesizeParseLlvm} + {@see JitGetimagesize} instead:
 * NestedJIT {@see __string__*} args and HashTable returns fail under user-script AOT
 * (#27291 / #26829 / peer #27051 / #26910). Kept as a documentation stub so older
 * require paths remain harmless.
 */
final class GetimagesizeJitHelper
{
}
