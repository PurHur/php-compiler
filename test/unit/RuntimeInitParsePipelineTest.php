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
        $this->assertStringContainsString('m5ArgvIdentityParsePrepare', $source);
        $this->assertStringContainsString('#26756', $source);
    }

    public function testM5DriverHostDefinesRuntimeParseSpineProps(): void
    {
        $path = dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $runtimePos = strpos($source, "'phpcompiler\\runtime' === \$lcname");
        $this->assertNotFalse($runtimePos);
        $chunk = substr($source, $runtimePos, 2400);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $chunk);
        $this->assertStringContainsString("'parser'", $chunk);
        $this->assertStringContainsString("'confusableBuiltinTypeHintCheck'", $chunk);
        $this->assertStringContainsString('m5ArgvIdentityParsePrepare', $chunk);
        // M5 host must not take the SELFHOST_AOT mode-only shortcut (#26756 SEGV).
        $this->assertMatchesRegularExpression(
            '/M5_DRIVER_HOST.*!\\$m5Host|!\\$m5Host.*SELFHOST_AOT|&&\\s*!\\$m5Host/s',
            $chunk
        );
    }

    public function testRuntimeParseSkipsPrepareWhenM5FlagSet(): void
    {
        $path = dirname(__DIR__, 2).'/lib/Runtime.php';
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('m5ArgvIdentityParsePrepare', $source);
        $parsePos = strpos($source, 'function parse(string $code, string $filename)');
        $this->assertNotFalse($parsePos);
        $chunk = substr($source, $parsePos, 1200);
        $this->assertStringContainsString('m5ArgvIdentityParsePrepare', $chunk);
        $flagPos = strpos($chunk, 'm5ArgvIdentityParsePrepare');
        $preparePos = strpos($chunk, 'prepareSourceForParser');
        $this->assertNotFalse($flagPos);
        $this->assertNotFalse($preparePos);
        $this->assertLessThan($preparePos, $flagPos, 'M5 flag gate must precede prepareSourceForParser');
    }

    public function testPrepareSpineIdentityWiredBeforeVoidStubs(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/lib/JIT/RuntimePrepareSpineIdentity.php');
        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $this->assertStringContainsString('RuntimePrepareSpineIdentity.php', $jit);
        $this->assertStringContainsString('ensureM5ArgvPrepareSpineIdentityStubs', $jit);
        $identityPos = strpos($jit, 'ensureM5ArgvPrepareSpineIdentityStubs()');
        $voidStubPos = strpos($jit, "foreach (['preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser']");
        $this->assertNotFalse($identityPos);
        $this->assertNotFalse($voidStubPos);
        $this->assertLessThan($voidStubPos, $identityPos, 'Identity stubs must register before void stub loop');
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
