<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** C-floor Runtime::initParsePipeline for M5 argv seed (#26756). */
final class RuntimeInitParsePipelineTest extends TestCase
{
    public function testFloorAllocatesParserAndPeers(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/JIT/RuntimeInitParsePipeline.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString("PHPCfg\\\\Parser", $source);
        $this->assertStringContainsString("PHPCfg\\\\Traverser", $source);
        $this->assertStringContainsString('NullSafeLivenessDetector', $source);
        $this->assertStringContainsString('AssignOp', $source);
        $this->assertStringContainsString('CompilerTypeReconstructor', $source);
        $this->assertStringContainsString('markObjectConstructed', $source);
        $this->assertStringContainsString('#26756', $source);
    }

    public function testJitWiresM5FloorBeforeNestedJit(): void
    {
        $jitPath = dirname(__DIR__, 2).'/lib/JIT.php';
        $this->assertFileExists($jitPath);
        $jit = (string) file_get_contents($jitPath);
        $this->assertStringContainsString('compileRuntimeInitParsePipelineM3Native', $jit);
        $this->assertStringContainsString('RuntimeInitParsePipeline::emit', $jit);
        $fnPos = strpos($jit, 'function compileRuntimeInitParsePipelineM3Native');
        $this->assertNotFalse($fnPos);
        $chunk = substr($jit, $fnPos, 2500);
        $floorPos = strpos($chunk, 'RuntimeInitParsePipeline::emit');
        $nestedPos = strpos($chunk, 'compileM3EmitTuRuntimeMethodFromRuntimePhpFile');
        $this->assertNotFalse($floorPos);
        $this->assertNotFalse($nestedPos);
        $this->assertLessThan($nestedPos, $floorPos, 'M5 C-floor must run before NestedJIT Runtime.php path');
        $this->assertStringContainsString('shouldUseM5DriverHostCompile()', $chunk);
    }
}
