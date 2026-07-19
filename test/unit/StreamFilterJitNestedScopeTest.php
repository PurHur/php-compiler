<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** JitStreamFilterKernel nested helper compile via JitVmHelperLink (#9047, #21041). */
final class StreamFilterJitNestedScopeTest extends TestCase
{
    public function testStreamFilterJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamFilterKernel.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('markJitIncludedFileCompiled', $source);
    }
}
