<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StreamFilterJit nested helper compile must use NestedJitCompileScope (#9047, #11142). */
final class StreamFilterJitNestedScopeTest extends TestCase
{
    public function testStreamFilterJitUsesNestedJitCompileScope(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamFilterJit.php');
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('markJitIncludedFileCompiled', $source);
    }
}
