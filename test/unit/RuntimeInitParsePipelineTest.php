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
        // Lightweight astParser peers — not PhpParser\Parser\Php7 (#27426 SEGV).
        $this->assertStringContainsString('astParser', $source);
        $this->assertStringContainsString('M5ParserAstPeer', $source);
        $this->assertStringNotContainsString("PhpParser\\\\Parser\\\\Php7", $source);
        $this->assertStringContainsString('final class M5ParserAstPeer', $source);
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
        // PHPCfg\Parser peer slots for C-floor wiring (#27426).
        $this->assertStringContainsString("'phpcfg\\\\parser'", $source);
        $this->assertStringContainsString("'astParser'", $source);
        $this->assertStringContainsString('astparser', $source);
    }

    public function testPrepareIdentityAvoidsStringSeparate(): void
    {
        $path = dirname(__DIR__, 2).'/lib/JIT/RuntimePrepareSpineIdentity.php';
        $source = (string) file_get_contents($path);
        $this->assertStringNotContainsString(
            "lookupFunction('__string__separate')",
            $source,
            'Prepare identity must not separate — NestedJIT string ABI SEGV (#26756)'
        );
        $this->assertStringContainsString('__hashtable__setStringAt', $source);
    }

    public function testM5ParseStringFormalAbiForced(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('runtime::parse', $jit);
        $this->assertStringContainsString('Type::string()', $jit);
        $pos = strpos($jit, 'M5 argv NestedJIT of Runtime::parse');
        $this->assertNotFalse($pos, 'ABI force comment for #26756 must remain');
        $this->assertStringContainsString('isM5NestedJitPhpCfgParserParse', $jit);
        $this->assertStringContainsString('effectiveReturnCallbackType', $jit);
        $this->assertStringContainsString('PHPCfg\\Parser::parse', $jit);
        $parserAbi = strpos($jit, 'M5 argv NestedJIT of PHPCfg\\Parser::parse');
        $this->assertNotFalse($parserAbi, 'Parser::parse ABI force for #27426 must remain');
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

    public function testM5ParseFloorWiredInsteadOfNestedJit(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/lib/JIT/RuntimeParseM5Native.php');
        $floor = (string) file_get_contents($root.'/lib/JIT/RuntimeParseM5Native.php');
        $this->assertStringContainsString('#26756', $floor);
        $this->assertStringContainsString('PHPCfg\\Parser', $floor);
        $this->assertFileExists($root.'/lib/JIT/RuntimeParseM5PhpCfgParser.php');
        $force = (string) file_get_contents($root.'/lib/JIT/RuntimeParseM5PhpCfgParser.php');
        $this->assertStringContainsString('PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT', $force);
        $this->assertStringContainsString('PHPCfg\\Parser::parse', $force);
        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $this->assertStringContainsString('RuntimeParseM5Native.php', $jit);
        $this->assertStringContainsString('RuntimeParseM5Native::emitFunction', $jit);
        $this->assertStringContainsString('RuntimeParseM5PhpCfgParser.php', $jit);
        $this->assertStringContainsString('RuntimeParseM5PhpCfgParser::ensureParse', $jit);
        // Within compileM3EmitTuRuntimeSpineMethodsForRealLowering, force-include precedes C-floor.
        $spineFn = strpos($jit, 'function compileM3EmitTuRuntimeSpineMethodsForRealLowering');
        $this->assertNotFalse($spineFn);
        $spineChunk = substr($jit, $spineFn, 8000);
        $forcePos = strpos($spineChunk, 'RuntimeParseM5PhpCfgParser::ensureParse');
        $floorPos = strpos($spineChunk, 'RuntimeParseM5Native::emitFunction');
        $this->assertNotFalse($forcePos, 'ensureParse must be wired in spine real-lower');
        $this->assertNotFalse($floorPos, 'C-floor emit must remain in spine real-lower');
        $this->assertLessThan($floorPos, $forcePos, 'Parser NestedJIT must precede C-floor parse emit');
        // M5 must stub NestedJIT of parse and emit C-floor instead.
        $stubPos = strpos($jit, "\$emitHelperStubMethods = array_merge(\$emitHelperStubMethods, [\n                    'parse'");
        if (false === $stubPos) {
            $stubPos = strpos($jit, "'parse',\n                    'initparsepipeline'");
        }
        $this->assertNotFalse($stubPos, 'M5 must list parse among emitHelperStubMethods');
        $smoke = (string) file_get_contents($root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $smoke);
        $this->assertStringContainsString('emitRuntimeParseAndCompileDefault', $smoke);
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
