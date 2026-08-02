<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Guard AOT Closure::call temporary-$this lowering (#26872). */
final class ClosureCallAotProxyTest extends TestCase
{
    public function testClosureCallProxyRegistered(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/ClosureBindHelper.php');
        $this->assertStringContainsString("functionProxies['closure::call']", $helper);
        $this->assertStringContainsString('function invokeCall(', $helper);
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/ClosureCall.php');
    }

    public function testReproScriptExists(): void
    {
        $path = dirname(__DIR__, 2).'/test/repro/maintainer_gap_aot_closure_call.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('->call(new A())', (string) file_get_contents($path));
    }
}
