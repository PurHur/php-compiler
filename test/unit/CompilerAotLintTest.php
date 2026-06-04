<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/Compiler.php, lib/JIT.php, lib/Doctor.php (php-types PHPDoc).
 */
final class CompilerAotLintTest extends TestCase
{
    public function testLibCompilerParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/Compiler.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    /**
     * @dataProvider genericPhpDocLintTargetProvider
     */
    public function testLibFileParseAndCompile(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/'.$relativePath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    /**
     * @dataProvider genericPhpDocLintTargetProvider
     */
    public function testLibFileCompileLintExitZero(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.$relativePath;
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$relativePath
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function genericPhpDocLintTargetProvider(): iterable
    {
        yield 'JIT' => ['lib/JIT.php'];
        yield 'Doctor' => ['lib/Doctor.php'];
    }

    public function testHelloWorldCompileDriverLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for compile_driver.php (#5263)'
        );
    }

    public function testNullsafeCoalesceAssignParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/fixtures/aot/cases/nullsafe_coalesce_assign_probe.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    /** Issue #5310: chained ?-> in read context with ?? must compile (lib/JIT.php pattern). */
    public function testNullsafePropertyFetchCoalesceReadContextCompiles(): void
    {
        $code = <<<'PHP'
<?php
class T { public ?object $type = null; }
class B { public function getOperand(int $i): object { return new T(); } }
function probe(B $block, int $argOffset): void {
    $classHint = $block->getOperand($argOffset)->type?->userType ?? null;
}
PHP;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile($code, 'nullsafe_coalesce_read.php');
        $this->assertNotNull($block);
    }
}
