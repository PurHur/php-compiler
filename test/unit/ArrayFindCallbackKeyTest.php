<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_find family php-src callbacks pass (value, key); forward-profile *_key variants use (key, value) (#17599).
 */
final class ArrayFindCallbackKeyTest extends TestCase
{
    private const CODE = <<<'PHP'
$a = ['x' => 10, 'y' => 20];
echo array_find($a, fn ($v, $k) => $k === 'y'), "\n";
echo array_find_key($a, fn ($v, $k) => $v === 20), "\n";
echo array_any($a, fn ($v, $k) => $k === 'x') ? 'y' : 'n', "\n";
echo array_all($a, fn ($v, $k) => is_int($v)) ? 'y' : 'n', "\n";
echo array_find_key([1, 2, 3], fn ($v, $k) => $v === 2), "\n";
PHP;

    private const EXPECT = <<<'TXT'
20
y
y
y
1
TXT;

    public function testVmCallbackKey(): void
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
    public function testJitCallbackKey(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertOutputMatches($this->runBin('bin/jit.php'));
    }

    /**
     * @group llvm
     * @group aot-lint
     */
    public function testAotLintCallbackKey(): void
    {
        if (!CompilerVersion::supportsPhp84ArraySearchFunctions()) {
            $this->markTestSkipped('array_find family withheld on PHP 8.2 reference profile (#14505)');
        }
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_find_key_cb_lint_');
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

    private function assertOutputMatches(string $out): void
    {
        $this->assertSame(
            rtrim(self::EXPECT)."\n",
            rtrim($out)."\n"
        );
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_find_key_cb_');
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
