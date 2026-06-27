<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** vfscanf() JIT embed routes through VfscanfJitHelper PHP not SscanfJit LLVM (#12541). */
final class StringVfscanfRuntimeShrinkTest extends TestCase
{
    public function testSscanfEmbedRoutesVfscanfThroughJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringVfscanf::implement', $source);
        $this->assertStringNotContainsString('SscanfJit::implementVfscanfOnly', $source);
    }

    public function testVfscanfJitHelperUsesVmVfscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/VfscanfJitHelper.php');
        $this->assertStringContainsString('VmVfscanf::parse', $source);
        $this->assertStringContainsString('SscanfJitHelper::packMetaFromVariables', $source);
    }
}
