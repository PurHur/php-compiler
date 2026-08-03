<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** M5 trivial-echo Script/Block builder for gen-0 functional smoke (#26756). */
final class M5TrivialEchoScriptTest extends TestCase
{
    public function testTryBuildMatchesFunctionalSmokeShape(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $code = "<?php\necho \"GEN0_FUNCTIONAL_OK tok\\n\";\n";
        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild($code, 'never-seen.php');
        $this->assertNotNull($script);
        $this->assertSame('{main}', $script->main->name);
        $this->assertCount(2, $script->main->cfg->children);
        $this->assertInstanceOf(\PHPCfg\Op\Terminal\Echo_::class, $script->main->cfg->children[0]);
        $this->assertInstanceOf(\PHPCfg\Op\Terminal\Return_::class, $script->main->cfg->children[1]);
        $echo = $script->main->cfg->children[0];
        $this->assertInstanceOf(\PHPCfg\Operand\Literal::class, $echo->expr);
        $this->assertSame("GEN0_FUNCTIONAL_OK tok\n", $echo->expr->value);
    }

    public function testParseAndCompileMatchesCompileEmitSmoke(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $code = "<?php\necho \"hi\\n\";\n";
        $block = \PHPCompiler\JIT\M5TrivialEchoScript::parseAndCompile($code, 't.php');
        $this->assertInstanceOf(\PHPCompiler\Block::class, $block);
        $this->assertSame(2, $block->nOpCodes);
        $this->assertSame(\PHPCompiler\OpCode::TYPE_ECHO, $block->opCodes[0]->type);
        $this->assertSame(\PHPCompiler\OpCode::TYPE_RETURN_VOID, $block->opCodes[1]->type);
        $this->assertSame("hi\n", $block->constants[0]->toString());

        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild($code, 't.php');
        $runtime = new \PHPCompiler\Runtime();
        $ref = $runtime->compileEmitSmoke($script);
        $this->assertSame($ref->nOpCodes, $block->nOpCodes);
        $this->assertSame($ref->constants[0]->toString(), $block->constants[0]->toString());
    }

    public function testTryBuildRejectsNonEcho(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $this->assertNull(\PHPCompiler\JIT\M5TrivialEchoScript::parseAndCompile('<?php $a=1;', 't.php'));
        $this->assertNull(\PHPCompiler\JIT\M5TrivialEchoScript::tryBuild('<?php echo 01;', 't.php'));
        // Single-quoted echo is supported (#27426).
        $sq = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild("<?php echo 'x';", 't.php');
        $this->assertNotNull($sq);
    }

    public function testTryBuildSingleQuotedEcho(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild("<?php echo 'hi\\n';", 'sq.php');
        $this->assertNotNull($script);
        // Single-quoted \\n is literal backslash + n, not newline.
        $block = \PHPCompiler\JIT\M5TrivialEchoScript::parseAndCompile("<?php echo 'ok';", 'sq.php');
        $this->assertNotNull($block);
        $esc = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild("<?php echo 'a\\\\b\\'c';", 'sq.php');
        $this->assertNotNull($esc);
    }

    public function testTryBuildEchoIntLiteral(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild('<?php echo 42;', 'ei.php');
        $this->assertNotNull($script, '#27426 echo <int> shape');
        $echo = $script->main->cfg->children[0];
        $this->assertInstanceOf(\PHPCfg\Op\Terminal\Echo_::class, $echo);
        $this->assertInstanceOf(\PHPCfg\Operand\Literal::class, $echo->expr);
        $this->assertSame('42', $echo->expr->value);
        $block = \PHPCompiler\JIT\M5TrivialEchoScript::parseAndCompile('<?php echo 42;', 'ei.php');
        $this->assertInstanceOf(\PHPCompiler\Block::class, $block);
        $this->assertSame('42', $block->constants[0]->toString());
    }

    public function testTryBuildAssignPlusEchoFoldsSum(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $code = '<?php $a = 1 + 2; echo $a;';
        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild($code, 'arith.php');
        $this->assertNotNull($script, '#27426 arith shape must match');
        $echo = $script->main->cfg->children[0];
        $this->assertInstanceOf(\PHPCfg\Op\Terminal\Echo_::class, $echo);
        $this->assertInstanceOf(\PHPCfg\Operand\Literal::class, $echo->expr);
        $this->assertSame('3', $echo->expr->value);

        $block = \PHPCompiler\JIT\M5TrivialEchoScript::parseAndCompile($code, 'arith.php');
        $this->assertInstanceOf(\PHPCompiler\Block::class, $block);
        $this->assertSame('3', $block->constants[0]->toString());

        $this->assertNull(
            \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild('<?php $a = 1 + 2; echo $b;', 't.php'),
            'echo var must match assign target'
        );
        $this->assertNull(
            \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild('<?php $a = 01 + 2; echo $a;', 't.php'),
            'leading-zero multi-digit rejected'
        );
    }

    public function testTryBuildAcceptsHelloWorldPreamble(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $hw = (string) file_get_contents(dirname(__DIR__, 2).'/examples/000-HelloWorld/example.php');
        $script = \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild($hw, 'example.php');
        $this->assertNotNull($script, 'HelloWorld declare+docblock+echo must match (#27426)');
        $echo = $script->main->cfg->children[0];
        $this->assertInstanceOf(\PHPCfg\Op\Terminal\Echo_::class, $echo);
        $this->assertInstanceOf(\PHPCfg\Operand\Literal::class, $echo->expr);
        $this->assertSame("Hello World\n", $echo->expr->value);
    }

    public function testStripLeadingPreambleLeavesEcho(): void
    {
        require_once dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoScript.php';
        $rest = \PHPCompiler\JIT\M5TrivialEchoScript::stripLeadingPreamble(
            "declare(strict_types=1);\n\n/** doc */\n// line\necho \"x\\n\";"
        );
        $this->assertSame('echo "x\n";', $rest);
    }

    public function testWiringPrefersTrivialEchoInParseAndCompileDefault(): void
    {
        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('M5TrivialEchoScript::lookup', $smoke);
        $this->assertStringContainsString('M5TrivialEchoNative::lookup', $smoke);
        $this->assertStringContainsString('emitRuntimeParseAndCompileDefaultFallback', $smoke);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('M5TrivialEchoScript.php', $jit);
        $this->assertStringContainsString('M5TrivialEchoNative.php', $jit);
        $this->assertStringContainsString('M5TrivialEchoNative::ensureParseAndCompile', $jit);
        // #27428: do not M3-content-match HelloWorld when M5 trivial-echo accepts it
        $this->assertStringContainsString('HELLOWORLD_SIDECAR_REL', $jit);
        $this->assertStringContainsString('M5TrivialEchoScript::tryBuild', $jit);
        $this->assertStringContainsString('PHP_COMPILER_M5_TRIVIAL_ECHO_NESTEDJIT', $jit);
        $native = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/M5TrivialEchoNative.php');
        $this->assertStringContainsString('__m5_te_try_extract', $native);
        $this->assertStringContainsString('__m5_te_try_extract_arith', $native);
        $this->assertStringContainsString('__m5_te_try_extract_echo_int', $native);
        $this->assertStringContainsString('__m5_te_emit_to_path', $native);
        $this->assertStringContainsString('#27426', $native);
    }
}
