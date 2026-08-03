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
        $this->assertStringContainsString('astParser', $source);
        $this->assertStringContainsString('M5ParserAstPeer', $source);
        $this->assertStringNotContainsString("PhpParser\\\\Parser\\\\Php7", $source);
        $this->assertStringContainsString('M5ParserAstPeer::class', $source);
        $objectType = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('m5parserastpeer', $objectType);
        $this->assertStringContainsString("'parse', 'traverse', 'addvisitor', 'begincompilationunit'", $objectType);
        $peerPath = $root.'/lib/JIT/M5ParserAstPeer.php';
        $this->assertFileExists($peerPath);
        $peer = (string) file_get_contents($peerPath);
        $this->assertStringContainsString('final class M5ParserAstPeer', $peer);
        $this->assertStringContainsString('implements \\PhpParser\\Parser', $peer);
        $this->assertStringContainsString('function parse(string $code', $peer);
        $this->assertStringContainsString('function traverse(array $nodes)', $peer);
        $this->assertStringContainsString('function beginCompilationUnit(string $fileName)', $peer);
    }

    public function testM5ParserAstPeerBuildsEchoAndArithAst(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        require_once dirname(__DIR__, 2).'/lib/JIT/M5ParserAstPeer.php';
        $peer = new \PHPCompiler\JIT\M5ParserAstPeer();
        $echoAst = $peer->parse("<?php echo \"hi\\n\";");
        $this->assertNotNull($echoAst);
        $this->assertCount(1, $echoAst);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Echo_::class, $echoAst[0]);
        $this->assertInstanceOf(
            \PhpParser\Node\Scalar\String_::class,
            $echoAst[0]->exprs[0]
        );
        $this->assertSame("hi\n", $echoAst[0]->exprs[0]->value);

        $arithAst = $peer->parse('<?php $a = 1 + 2; echo $a;');
        $this->assertNotNull($arithAst);
        $this->assertCount(2, $arithAst);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Expression::class, $arithAst[0]);
        $this->assertInstanceOf(\PhpParser\Node\Expr\Assign::class, $arithAst[0]->expr);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Echo_::class, $arithAst[1]);

        // Peer + TrivialEcho both accept echo <int>; Zend peer→PHPCfg Script (#27426).
        $echoInt = $peer->parse('<?php echo 42;');
        $this->assertNotNull($echoInt);
        $this->assertCount(1, $echoInt);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Echo_::class, $echoInt[0]);
        $this->assertInstanceOf(\PhpParser\Node\Scalar\LNumber::class, $echoInt[0]->exprs[0]);
        $this->assertSame(42, $echoInt[0]->exprs[0]->value);
        $this->assertNull($peer->parse('<?php echo 01;'));
        $this->assertNull($peer->parse('<?php function f($x){return $x;} echo f(1);'));
        $sq = $peer->parse("<?php echo 'sq';");
        $this->assertNotNull($sq);
        $this->assertCount(1, $sq);
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Echo_::class, $sq[0]);
        $this->assertInstanceOf(\PhpParser\Node\Scalar\String_::class, $sq[0]->exprs[0]);
        $this->assertSame('sq', $sq[0]->exprs[0]->value);
        $this->assertSame($arithAst, $peer->traverse($arithAst));
        $peer->beginCompilationUnit('t.php');
        $peer->addVisitor(new \stdClass());
    }

    public function testM5ParserAstPeerFeedsPhpCfgParser(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        require_once dirname(__DIR__, 2).'/lib/JIT/M5ParserAstPeer.php';
        $peer = new \PHPCompiler\JIT\M5ParserAstPeer();
        $cfgParser = new \PHPCfg\Parser($peer);
        $script = $cfgParser->parse("<?php echo \"ok\\n\";", 'peer-echo.php');
        $this->assertInstanceOf(\PHPCfg\Script::class, $script);
        $this->assertNotEmpty($script->main->cfg->children);

        $arith = $cfgParser->parse('<?php $a = 1 + 2; echo $a;', 'peer-arith.php');
        $this->assertInstanceOf(\PHPCfg\Script::class, $arith);
        $this->assertGreaterThanOrEqual(2, count($arith->main->cfg->children));

        // Integer echo → Script via peer AST (same shape as TrivialEcho C-floor).
        $echoInt = $cfgParser->parse('<?php echo 42;', 'peer-echo-int.php');
        $this->assertInstanceOf(\PHPCfg\Script::class, $echoInt);
        $this->assertNotEmpty($echoInt->main->cfg->children);
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
        $this->assertMatchesRegularExpression(
            '/M5_DRIVER_HOST.*!\\$m5Host|!\\$m5Host.*SELFHOST_AOT|&&\\s*!\\$m5Host/s',
            $chunk
        );
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
        $this->assertFileExists($root.'/lib/JIT/RuntimeParseM5AstPeer.php');
        $peerForce = (string) file_get_contents($root.'/lib/JIT/RuntimeParseM5AstPeer.php');
        $this->assertStringContainsString('M5ParserAstPeer', $peerForce);
        $this->assertStringContainsString('ensureMethods', $peerForce);
        $this->assertStringContainsString('REQUIRED_SURFACE', $peerForce);
        // Must NestedJIT private helpers too — parse() calls tryEchoStringAst etc. (#27426).
        $this->assertStringNotContainsString(
            "private const METHODS = ['parse', 'traverse', 'addvisitor', 'begincompilationunit']",
            $peerForce,
            'Surface-only METHODS filter soft-failed NestedJIT of parse helpers'
        );
        $this->assertStringContainsString('tryechostringast', (string) file_get_contents(
            $root.'/lib/JIT/Builtin/Type/Object_.php'
        ));
        $this->assertStringContainsString('tryechointast', (string) file_get_contents(
            $root.'/lib/JIT/Builtin/Type/Object_.php'
        ));
        $this->assertStringContainsString('tryEchoIntAst', (string) file_get_contents(
            $root.'/lib/JIT/M5ParserAstPeer.php'
        ));
        $this->assertStringContainsString('REQUIRED_SURFACE', $peerForce);
        $this->assertStringContainsString('PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT_CALL', $floor);
        $this->assertStringContainsString('shouldCallNestedJitParser', $floor);
        $this->assertStringContainsString('stripLeadingPreamble', (string) file_get_contents($root.'/lib/JIT/M5ParserAstPeer.php'));
        // NestedJIT every class method — private helpers are called from parse() (#27426).
        $this->assertStringNotContainsString(
            "private const METHODS = ['parse', 'traverse', 'addvisitor', 'begincompilationunit']",
            $peerForce
        );
        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $this->assertStringContainsString('RuntimeParseM5Native.php', $jit);
        $this->assertStringContainsString('RuntimeParseM5Native::emitFunction', $jit);
        $this->assertStringContainsString('RuntimeParseM5PhpCfgParser.php', $jit);
        $this->assertStringContainsString('RuntimeParseM5PhpCfgParser::ensureParse', $jit);
        $this->assertStringContainsString('RuntimeParseM5AstPeer.php', $jit);
        $this->assertStringContainsString('RuntimeParseM5AstPeer::ensureMethods', $jit);
        $this->assertStringContainsString('M5ParserAstPeer.php', $jit);
        $spineFn = strpos($jit, 'function compileM3EmitTuRuntimeSpineMethodsForRealLowering');
        $this->assertNotFalse($spineFn);
        $spineChunk = substr($jit, $spineFn, 8000);
        $peerPos = strpos($spineChunk, 'RuntimeParseM5AstPeer::ensureMethods');
        $forcePos = strpos($spineChunk, 'RuntimeParseM5PhpCfgParser::ensureParse');
        $floorPos = strpos($spineChunk, 'RuntimeParseM5Native::emitFunction');
        $this->assertNotFalse($peerPos, 'ensureMethods must be wired in spine real-lower');
        $this->assertNotFalse($forcePos, 'ensureParse must be wired in spine real-lower');
        $this->assertNotFalse($floorPos, 'C-floor emit must remain in spine real-lower');
        $this->assertLessThan($forcePos, $peerPos, 'Peer NestedJIT must precede Parser NestedJIT');
        $this->assertLessThan($floorPos, $forcePos, 'Parser NestedJIT must precede C-floor parse emit');
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
        if (false !== $stubPos) {
            $this->assertLessThan($stubPos, $floorPos, 'M5 C-floor must run before void-stub gate');
        }
        $this->assertStringContainsString('shouldUseM5DriverHostCompile()', $chunk);
    }
}
