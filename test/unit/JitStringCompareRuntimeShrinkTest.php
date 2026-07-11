<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** String compare JIT routes through VmStringCompare PHP SSOT (#9972). */
final class JitStringCompareRuntimeShrinkTest extends TestCase
{
    public function testJitStringCompareIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitStringCompare.php');
        $this->assertStringContainsString('VmStringCompare', $source);
        $this->assertStringNotContainsString('memcmp', $source);
        $this->assertStringNotContainsString('jit_strcmp_len_ok', $source);
        $this->assertLessThanOrEqual(65, substr_count($source, "\n") + 1);
    }

    public function testVmStringCompareOwnsMemcmpLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmStringCompare.php');
        $this->assertStringContainsString('memcmp', $source);
        $this->assertStringContainsString('suffixIdentical', $source);
        $this->assertGreaterThan(180, substr_count($source, "\n") + 1);
    }
}
