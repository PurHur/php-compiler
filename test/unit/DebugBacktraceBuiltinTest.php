<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** debug_backtrace() VM/JIT smoke and compliance (issue #1378). */
final class DebugBacktraceBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'debug_backtrace.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/debug_backtrace.phpt',
            'debug_backtrace.phpt'
        );
    }

    public function testVmDebugBacktraceInline(): void
    {
        $code = <<<'PHP'
function inner() {
    $t = debug_backtrace();
    echo $t[0]['function'], '|', $t[1]['function'], '|', $t[2]['function'], "\n";
    echo isset($t[0]['file']) ? 'keys' : 'nokeys', "\n";
    echo $t[0]['line'], "\n";
}
function outer() {
    inner();
}
outer();
PHP;
        $this->assertSame("debug_backtrace|inner|outer\nkeys\n0\n", $this->runInline($code));
    }

    private function runInline(string $code, string $bin = 'vm'): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_bt_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $runner = 'jit' === $bin ? $repo.'/bin/jit.php' : $repo.'/bin/vm.php';
        $proc = proc_open(
            [PHP_BINARY, $runner, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $stderr ?: 'VM run failed');
        @unlink($tmp);

        return $stdout;
    }
}
