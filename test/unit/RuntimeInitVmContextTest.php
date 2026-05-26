<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Native Runtime::initVmContext LLVM spine (#1514, #1056, #1768). */
final class RuntimeInitVmContextTest extends TestCase
{
    public function testEmitWiresVmContextSubobjects(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root.'/lib/JIT/RuntimeInitVmContext.php');
        $this->assertStringContainsString('ErrorReporter', $source);
        $this->assertStringContainsString('ScriptStack', $source);
        $this->assertStringContainsString("'errors'", $source);
        $this->assertStringContainsString("'scriptStack'", $source);
        $this->assertStringContainsString("'functions'", $source);
        $this->assertStringContainsString('HashTableHelper::alloc', $source);
    }

    public function testM3EmitTuConstructCallsInitVmContextFloor(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('RuntimeInitVmContext::emit', $jit);
        $this->assertStringContainsString('emitM3EmitTuRuntimeConstructNativeFunction', $jit);
    }

    public function testSpineShimsExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/test/bootstrap-aot/llvm_env_spine_shim.php');
        $this->assertFileExists($root.'/test/bootstrap-aot/macro_functions_spine_shim.php');
    }
}
