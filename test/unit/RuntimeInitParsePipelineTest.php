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
        $chunk = substr($jit, $fnPos, 3500);
        $m5Pos = strpos($chunk, 'shouldUseM5DriverHostCompile()');
        $floorPos = strpos($chunk, 'RuntimeInitParsePipeline::emit');
        $stubPos = strpos($chunk, "shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')");
        $this->assertNotFalse($m5Pos);
        $this->assertNotFalse($floorPos);
        $this->assertLessThan($floorPos, $m5Pos, 'M5 gate must wrap C-floor emit');
        // M5 C-floor must be consulted before void-stub short-circuit (#26756).
        if (false !== $stubPos) {
            $this->assertLessThan($stubPos, $floorPos, 'M5 C-floor must run before void-stub gate');
        }
        $this->assertStringContainsString('shouldUseM5DriverHostCompile()', $chunk);
    }
}
