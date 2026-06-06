<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\sys_getloadavg;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM/JIT/AOT builtin for sys_getloadavg() (#3464). */
final class SysGetloadavgBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$avg = sys_getloadavg();
if (false === $avg) {
    echo "false\n";
} else {
    echo count($avg) === 3 ? "three\n" : "bad_count\n";
}
PHP;

    public function testReturnsLoadArrayOrFalse(): void
    {
        $runtime = new Runtime();
        $fn = new sys_getloadavg();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $expected = \sys_getloadavg();
        if (false === $expected) {
            $this->assertTrue($resolved->toBool() === false);
        } else {
            $this->assertSame(VMVariable::TYPE_ARRAY, $resolved->type);
            $ht = $resolved->toArray();
            $this->assertSame(3, $ht->getNumElements());
            for ($i = 0; $i < 3; ++$i) {
                $elem = $ht->findIndex($i);
                $this->assertNotNull($elem);
                $this->assertSame((float) $expected[$i], $elem->resolveIndirect()->toFloat());
            }
        }
    }

    public function testTooManyArgsThrowsArgumentCountError(): void
    {
        $runtime = new Runtime();
        $fn = new sys_getloadavg();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = new VMVariable();
        $frame->calledArgs[0]->int(1);
        $this->expectException(\ArgumentCountError::class);
        $fn->execute($frame);
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesVmSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (!\function_exists('sys_getloadavg') || false === \sys_getloadavg()) {
            $this->markTestSkipped('sys_getloadavg unavailable on host');
        }
        $this->assertSame("three\n", $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sys_getloadavg_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
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
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileCode = proc_close($compile);
        $this->assertSame(0, $compileCode, $compileErr ?: 'compile failed');
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $runErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $runCode = proc_close($run);
        @unlink($tmp);
        @unlink($out);
        $this->assertSame(0, $runCode, $runErr ?: 'run failed');

        return $stdout;
    }
}
