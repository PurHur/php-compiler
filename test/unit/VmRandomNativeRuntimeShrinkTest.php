<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM random_bytes/uniqid — no host \\fopen/\\gettimeofday delegation (#8402). */
final class VmRandomNativeRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmStringUsesNativeRandomAndWallClock(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmString.php');
        $this->assertStringContainsString('VmRandomNative::randomBytes', $source);
        $this->assertStringContainsString('VmDate::wallClock()', $source);
        $this->assertStringNotContainsString("\\fopen('/dev/urandom'", $source);
        $this->assertStringNotContainsString('\\gettimeofday()', $source);
    }

    public function testVmRandomNativeUsesLibcFfi(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmRandomNative.php');
        $this->assertStringContainsString('getrandom', $source);
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringNotContainsString('\\fopen', $source);
    }

    public function testRandomBytesVmReturnsRequestedLength(): void
    {
        if (!\PHPCompiler\ext\standard\VmRandomNative::available()) {
            $this->markTestSkipped('FFI unavailable');
        }
        $bytes = \PHPCompiler\ext\standard\VmString::randomBytes(16);
        $this->assertSame(16, \strlen($bytes));
    }

    public function testUniqidVmReturnsNonEmpty(): void
    {
        $id = \PHPCompiler\ext\standard\VmString::uniqid('pfx', true);
        $this->assertStringStartsWith('pfx', $id);
        $this->assertGreaterThan(3, \strlen($id));
    }
}
