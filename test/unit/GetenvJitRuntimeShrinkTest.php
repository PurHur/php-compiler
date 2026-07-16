<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv embed + deferred AOT: PHP helper + ext kernel (#9092, #13194, #13621, #19373). */
final class GetenvJitRuntimeShrinkTest extends TestCase
{
    public function testStringGetenvUsesJitHelperAndExtKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('GetenvJitHelper', $source);
        $this->assertStringContainsString('implementGetenvBridge', $source);
        $this->assertStringContainsString('JitGetenvKernel', $source);
        $this->assertStringContainsString('getenv_kernel_entry', $source);
        $this->assertStringNotContainsString('getenv_libc_entry', $source);
        $this->assertStringNotContainsString('LibcExtern::register', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGetenvLibcBridge.php');
        $this->assertStringNotContainsString('StringGetenvLibcBridge', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetenvKernel.php');
    }

    public function testSpineBundleIncludesGetenvKernel(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetenvJitHelper.php', $spine);
        $this->assertStringContainsString('JitGetenvKernel.php', $spine);
        $this->assertStringContainsString('StringGetenv.php', $spine);
    }
}
