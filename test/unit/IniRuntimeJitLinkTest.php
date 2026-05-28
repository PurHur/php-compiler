<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\Builtin\IniRuntime;

/** @group llvm */
final class IniRuntimeJitLinkTest extends TestCase
{
    public function testIniRuntimeBitcodeBuilds(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $ref = new \ReflectionClass(IniRuntime::class);
        $method = $ref->getMethod('ensureBitcode');
        $method->setAccessible(true);
        $bc = $method->invoke(null);
        $this->assertFileExists($bc);
        $this->assertGreaterThan(100, filesize($bc));
    }
}
