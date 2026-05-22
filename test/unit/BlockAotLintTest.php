<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/Block.php and lib/Compiler.php (php-types doc comment coercion).
 */
final class BlockAotLintTest extends TestCase
{
    public function testLibBlockParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/Block.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    public function testLibBlockCompileLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/lib/Block.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for lib/Block.php'
        );
    }

    public function testBootstrapInstanceofFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/instanceof_check.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    /**
     * @dataProvider aotLintTargetProvider
     */
    public function testLibFileParseWithoutDocCommentTypeError(string $relativePath, bool $expectSuccess): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/'.$relativePath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        try {
            $script = $runtime->parse((string) file_get_contents($path), $path);
            if ($expectSuccess) {
                $this->assertNotNull($script);
            }
        } catch (\TypeError $e) {
            $this->assertStringNotContainsString(
                'preg_match',
                $e->getMessage(),
                'php-types must coerce PhpParser\\Comment\\Doc before preg_match (php-types-doc-comment-string.patch)'
            );
            throw $e;
        } catch (\RuntimeException $e) {
            if ($expectSuccess) {
                throw $e;
            }
            // Compiler.php: past doc-comment coercion; further type-decl gaps are out of scope here.
            $this->assertStringNotContainsString('preg_match', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function aotLintTargetProvider(): iterable
    {
        yield 'Block' => ['lib/Block.php', true];
        yield 'Compiler' => ['lib/Compiler.php', false];
    }
}
