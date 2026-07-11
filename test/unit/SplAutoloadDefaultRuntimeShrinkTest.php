<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** spl_autoload()/spl_autoload_extensions() JIT routes through SplAutoloadDefaultJitHelper PHP (#4256). */
final class SplAutoloadDefaultRuntimeShrinkTest extends TestCase
{
    public function testSplAutoloadDefaultRuntimeUsesJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SplAutoloadDefaultRuntime.php');
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/SplAutoloadDefaultJitHelper.php');
        $this->assertStringContainsString('SplAutoloadDefaultJitHelper', $runtime);
        $this->assertStringContainsString('VmSplAutoload::defaultAutoload', $helper);
        $this->assertStringContainsString('VmSplAutoload::fileExtensions', $helper);
    }

    public function testSplAutoloadBuiltinDelegatesToJitLowering(): void
    {
        $autoload = (string) file_get_contents(__DIR__.'/../../ext/standard/spl_autoload.php');
        $extensions = (string) file_get_contents(__DIR__.'/../../ext/standard/spl_autoload_extensions.php');
        $this->assertStringContainsString('JitSplAutoloadDefault::autoload', $autoload);
        $this->assertStringContainsString('JitSplAutoloadDefault::extensions', $extensions);
        $this->assertStringNotContainsString('not implemented for JIT', $autoload);
        $this->assertStringNotContainsString('not implemented for JIT', $extensions);
    }
}
