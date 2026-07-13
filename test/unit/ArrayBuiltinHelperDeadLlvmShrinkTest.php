<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Dead array_diff/intersect/asort/replace_key LLVM deleted from ArrayBuiltinHelper after PHP runtime bridges (#18407, #18430). */
final class ArrayBuiltinHelperDeadLlvmShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 1730;

    public function testArrayBuiltinHelperDeadDiffIntersectLlvmRemoved(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        foreach ([
            'function arrayDiff(',
            'function arrayDiffAssoc(',
            'function arrayDiffKey(',
            'function arrayIntersect(',
            'function arrayIntersectAssoc(',
            'function arrayReplaceRecursive(',
            'function arrayReplace(',
            'function arrayReplaceKey(',
            'function asortByValue(',
            'function natsortByValue(',
            'function fillKeys(',
            'overlayExistingKeysOnly',
            'overlayHashTable',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source, $needle);
        }

        $lines = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead diff/intersect/asort/replace_key LLVM deletion (#18407, #18430)'
        );
    }
}
