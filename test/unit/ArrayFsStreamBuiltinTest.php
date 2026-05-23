<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_replace / array_intersect / tempnam / fgetc VM/AOT smoke.
 */
final class ArrayFsStreamBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$a = array('a' => 1, 'b' => 2);
$b = array('b' => 9, 'c' => 3);
$r = array_replace($a, $b);
echo $r['a'], "\n";
echo $r['b'], "\n";
echo $r['c'], "\n";
$x = array(1, 2, 3);
$y = array(2, 4);
$i = array_intersect($x, $y);
echo count($i), "\n";
echo $i[1], "\n";
$p = tempnam(sys_get_temp_dir(), 'phpc_arrfs_');
if (is_string($p)) {
    echo "temp\n";
    @unlink($p);
} else {
    echo "notemp\n";
}
$fp = fopen('test/compliance/cases/stdlib/fgetc_fixture.txt', 'r');
echo fgetc($fp), "\n";
fclose($fp);
PHP;

    private const AOT_CODE = <<<'PHP'
$r = array_replace(array('x' => 1), array('x' => 2));
echo $r['x'], "\n";
$i = array_intersect(array(1, 2), array(2));
echo count($i), "\n";
$p = tempnam(sys_get_temp_dir(), 'phpc_aot_arrfs_');
if (is_string($p)) {
    @unlink($p);
    echo "ok\n";
} else {
    echo "no\n";
}
PHP;

    private const EXPECT = "1\n9\n3\n1\n2\ntemp\nH\n";
    private const AOT_EXPECT = "2\n1\nok\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::AOT_EXPECT, $this->runAotBinary(self::AOT_CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_arrfs_aot_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $stderr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run), trim((string) $stderr));
        @unlink($tmp);
        @unlink($out);

        return $this->normalize((string) $stdout);
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_arrfs_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $stderr));
        @unlink($tmp);

        return $this->normalize((string) $stdout);
    }

    private function normalize(string $stdout): string
    {
        return str_replace("\r\n", "\n", $stdout);
    }
}
