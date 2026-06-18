<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPUnit\Framework\TestCase;

/** JIT random_bytes — /dev/urandom open/read, not getrandom(3) LLVM (#9113). */
final class StringRandomBytesRuntimeShrinkTest extends TestCase
{
    public function testStringRandomBytesDoesNotUseGetrandom(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringContainsString("lookupFunction('open')", $source);
        $this->assertStringContainsString("lookupFunction('read')", $source);
        $this->assertStringContainsString('VmRandomPure', $source);
        $this->assertDoesNotMatchRegularExpression("/lookupFunction\\('getrandom'\\)/", $source);
    }

    public function testJitRandomBytesDelegatesToCompilerHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytes.php');
        $this->assertStringContainsString('__compiler_random_bytes', $source);
        $this->assertDoesNotMatchRegularExpression("/lookupFunction\\('getrandom'\\)/", $source);
    }

    public function testImplementDefinesRandomBytesHelper(): void
    {
        if (!class_exists(StringRandomBytes::class)) {
            require_once __DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php';
        }
        $this->assertTrue(method_exists(StringRandomBytes::class, 'implement'));
    }
}
