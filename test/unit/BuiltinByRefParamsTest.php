<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** By-reference builtin parameter metadata (issue #3161, #3583). */
final class BuiltinByRefParamsTest extends TestCase
{
    public function testSimilarTextThirdArgument(): void
    {
        $this->assertSame([2], BuiltinByRefParams::forFunction('similar_text'));
        $this->assertSame([2], BuiltinByRefParams::forFunction('SIMILAR_TEXT'));
    }
}
