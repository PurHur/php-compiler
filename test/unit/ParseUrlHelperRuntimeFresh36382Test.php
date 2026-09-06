<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\AOT\HelperRuntimeCache;
use PHPUnit\Framework\TestCase;

/**
 * ParseUrlJitHelper must NestedJIT into the user AOT module (#36382).
 *
 * Prelinked unit.o returned [] for runtime URL strings under HELPER_RUNTIME_O=1;
 * USER_SCRIPT_INLINE_ONLY forces Preg-free NestedJIT (same pattern as SprintfJitHelper).
 *
 * @group unit
 */
final class ParseUrlHelperRuntimeFresh36382Test extends TestCase
{
    public function testParseUrlHelpersForcedUserScriptInlineOnly(): void
    {
        $ref = new \ReflectionClass(HelperRuntimeCache::class);
        $const = $ref->getConstant('USER_SCRIPT_INLINE_ONLY_LOGICALS');
        $this->assertIsArray($const);
        foreach (
            [
                'phpcompiler\\ext\\standard\\parseurljithelper::pathof',
                'phpcompiler\\ext\\standard\\parseurljithelper::schemeof',
                'phpcompiler\\ext\\standard\\parseurljithelper::hostof',
                'phpcompiler\\ext\\standard\\parseurljithelper::portof',
            ] as $logical
        ) {
            $this->assertArrayHasKey(
                $logical,
                $const,
                'ParseUrlJitHelper leaves must stay USER_SCRIPT_INLINE_ONLY so Slim Uri NestedJITs (#36382)'
            );
        }
    }
}
