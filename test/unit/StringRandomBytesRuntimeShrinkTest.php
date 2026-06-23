<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\RandomBytesJitHelper;
use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPUnit\Framework\TestCase;

/** JIT random_bytes routes through RandomBytesJitHelper PHP, not /dev/urandom LLVM (#9149). */
final class StringRandomBytesRuntimeShrinkTest extends TestCase
{
    public function testStringRandomBytesRoutesThroughRandomBytesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('RandomBytesJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('open')", $source);
        $this->assertStringNotContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString('/dev/urandom', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testRandomBytesJitHelperDelegatesToVmRandomPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RandomBytesJitHelper.php');
        $this->assertStringContainsString('VmRandomPure::randomBytes', $source);
    }

    public function testJitRandomBytesDelegatesToCompilerHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytes.php');
        $this->assertStringContainsString('__compiler_random_bytes', $source);
    }

    public function testRandomBytesJitHelperReturnsRequestedLength(): void
    {
        if (!\is_readable('/dev/urandom')) {
            $this->markTestSkipped('/dev/urandom not readable');
        }
        $bytes = RandomBytesJitHelper::randomBytes(16);
        $this->assertSame(16, \strlen($bytes));
    }

    public function testImplementDefinesRandomBytesBridge(): void
    {
        if (!class_exists(StringRandomBytes::class)) {
            require_once __DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php';
        }
        $this->assertTrue(method_exists(StringRandomBytes::class, 'implement'));
    }
}
