<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5492 / #6277: version_compare LLVM path replaces phpc_info.c / phpc_version_compare.c.
 *
 * @group aot-lint
 */
final class StringVersionCompareStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesVersionCompareC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_info.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_version_compare.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_info.c', $linker);
        $this->assertStringNotContainsString('phpc_version_compare.c', $linker);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('VersionCompareJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_version_compare', $bridge);
        $jitShim = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVersionCompareJit.php');
        $this->assertLessThan(20, substr_count($jitShim, "\n"), 'StringVersionCompareJit must be a thin shim');
    }
}
