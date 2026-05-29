<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile gate for lib units (issue #84, #57 try/catch lint).
 */
final class RuntimeAotLintTest extends TestCase
{
    /**
     * @dataProvider libFilesUsingClassConstFetch
     */
    public function testLibFileParseAndCompile(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/'.$relativePath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    public function testBootstrapClassConstFetchFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/class_const_fetch.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    public function testBootstrapTryCatchFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/try_catch.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
        $this->assertTryCatchLowering($block);
    }

    public function testBootstrapCastIntFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/cast_int.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
        $this->assertTrue(
            $this->blockTreeHasOpcode($block, OpCode::TYPE_CAST_INT),
            'expected TYPE_CAST_INT lowering in bootstrap cast_int fixture'
        );
    }

    public function testBootstrapCastStringFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/cast_string.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
        $this->assertTrue(
            $this->blockTreeHasOpcode($block, OpCode::TYPE_CAST_STRING),
            'expected TYPE_CAST_STRING lowering in bootstrap cast_string fixture'
        );
    }

    public function testLibRuntimeCompileLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/lib/Runtime.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for lib/Runtime.php'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function libFilesUsingClassConstFetch(): array
    {
        return [
            'Runtime.php' => ['lib/Runtime.php'],
            'VM.php' => ['lib/VM.php'],
        ];
    }

    private function assertTryCatchLowering(Block $block): void
    {
        $this->assertTrue(
            $this->blockTreeHasOpcode($block, OpCode::TYPE_TRY),
            'expected TYPE_TRY lowering in bootstrap_try_catch fixture'
        );
    }

    private function blockTreeHasOpcode(Block $block, int $opcode): bool
    {
        foreach ($block->opCodes as $op) {
            if ($opcode === $op->type) {
                return true;
            }
            if (null !== $op->block1 && $this->blockTreeHasOpcode($op->block1, $opcode)) {
                return true;
            }
            if (null !== $op->block2 && $this->blockTreeHasOpcode($op->block2, $opcode)) {
                return true;
            }
        }

        return false;
    }

    public function testFormatParseAndCompileNullDetailWhenScriptMissing(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);

        $this->assertSame('parse returned null', $runtime->formatParseAndCompileNullDetail(null));
    }

    public function testGetLastParseFailureRecordsThrownCompileError(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $code = '<?php $x = fn() => 1;';

        try {
            $runtime->parseAndCompile($code, 'arrow.php');
            $this->fail('expected LogicException for arrow function');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Expr_ArrowFunction', $e->getMessage());
        }

        $this->assertSame(
            'Unsupported expression: Expr_ArrowFunction',
            Runtime::getLastParseFailure()
        );
    }

    public function testNoteParseCompileNullForScriptRecordsDetail(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $runtime->noteParseCompileNullForScript(null);

        $this->assertSame('parse returned null', Runtime::getLastParseFailure());
        $this->assertSame('parse returned null', $runtime->peekLastParseFailure());
    }
}
