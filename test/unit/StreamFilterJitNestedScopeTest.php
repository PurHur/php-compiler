<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** JitStreamFilterKernel nested helper compile must use NestedJitCompileScope (#9047, #11142, #19644). */
final class StreamFilterJitNestedScopeTest extends TestCase
{
    public function testStreamFilterJitUsesNestedJitCompileScope(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamFilterKernel.php');
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('markJitIncludedFileCompiled', $source);
    }
}
