<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_find / array_find_key / array_any / array_all VM + JIT + AOT lint (#3073).
 *
 * php-src: ext/standard/array.c
 */
final class ArrayFindBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
function gt2(int $v): bool
{
    return $v > 2;
}

$a = [1, 2, 3, 4];
echo array_find($a, fn ($v) => $v > 2), "\n";
echo array_find_key($a, fn ($v) => $v > 2), "\n";
echo array_any($a, fn ($v) => $v > 3) ? 'y' : 'n', "\n";
echo array_all($a, fn ($v) => $v > 0) ? 'y' : 'n', "\n";

$b = ['x' => 10, 'y' => 20];
echo array_find($b, fn ($v) => $v > 15), "\n";
echo array_find_key($b, fn ($v) => $v > 15), "\n";

echo array_find([1, 2], fn ($v) => $v > 5) === null ? 'null' : 'bad', "\n";
echo array_find_key([1, 2], fn ($v) => $v > 5) === null ? 'null' : 'bad', "\n";

echo array_any([], fn ($v) => true) ? 'y' : 'n', "\n";
echo array_all([], fn ($v) => true) ? 'y' : 'n', "\n";

echo array_find($a, 'gt2'), "\n";

echo array_all([1, 2, 3], fn ($v) => is_int($v)) ? 'y' : 'n', "\n";
echo array_any([1, 2, 3], fn ($v) => is_string($v)) ? 'y' : 'n', "\n";
echo array_find([1, 2, 3], fn ($v) => is_int($v)), "\n";
PHP;

    private const EXPECT = <<<'TXT'
3
2
y
y
20
y
null
null
n
y
3
y
n
1
TXT;

    public function testVmMatchesSubset(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        $this->assertOutputMatches($this->runBin('bin/vm.php'));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testJitMatchesSubset(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertOutputMatches($this->runBin('bin/jit.php'));
    }

    private function assertOutputMatches(string $out): void
    {
        $this->assertSame(
            rtrim(self::EXPECT)."\n",
            rtrim($out)."\n"
        );
    }

    /**
     * @group llvm
     * @group aot-lint
     */
    public function testAotLintCompilesSubset(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_find_lint_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/bin/compile.php', '-l', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $stderr));
        @unlink($tmp);
    }

    /**
     * User-script AOT execute for Closure predicates (#26824, #27296) — NestedJIT helper path
     * previously crashed at emit; ArrayFindLlvm must match VM for packed and string keys.
     *
     * @group llvm
     * @group aot
     */
    public function testAotNativeBinaryMatchesClosureSubset(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $code = <<<'PHP'
$a = [10, 20, 30];
echo array_find($a, fn ($x) => $x > 15), "\n";
echo array_find_key($a, fn ($x) => $x > 15), "\n";
echo array_any($a, fn ($x) => $x > 25) ? "any\n" : "noany\n";
echo array_all($a, fn ($x) => $x > 5) ? "all\n" : "noall\n";
echo array_find([1, 2], fn ($x) => $x > 5) === null ? "null\n" : "bad\n";
$b = ['a' => 1, 'b' => 2];
echo array_find_key($b, fn ($v) => $v === 2), "\n";
echo array_find($b, fn ($v) => $v === 2), "\n";
echo array_any($b, fn ($v) => $v === 2) ? "sany\n" : "nosany\n";
echo array_find_key($b, fn ($v, $k) => $k === 'b'), "\n";
PHP;
        $expect = "20\n1\nany\nall\nnull\nb\n2\nsany\nb\n";
        $src = tempnam(sys_get_temp_dir(), 'phpc_af_src_');
        $bin = tempnam(sys_get_temp_dir(), 'phpc_af_bin_');
        $this->assertNotFalse($src);
        $this->assertNotFalse($bin);
        @unlink($bin);
        file_put_contents($src, "<?php\n".$code);
        $env = $_ENV;
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $bin, $src],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $stderr));
        $this->assertFileExists($bin);
        $out = shell_exec(escapeshellarg($bin));
        @unlink($src);
        @unlink($bin);
        $this->assertSame($expect, $out);
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_find_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $bin.' failed');
        @unlink($tmp);

        return $out;
    }
}
