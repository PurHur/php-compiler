<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplDoublyLinkedList offsetGet/Unset/Exists(null) soft-null (#31804).
 */
final class Issue31804DllistOffsetNullSoftTest extends TestCase
{
    public function testVmOffsetNullSoft(): void
    {
        $this->assertSame($this->expectedOutput(), $this->runBin('bin/vm.php'));
    }

    public function testJitOffsetNullSoft(): void
    {
        $this->assertSame($this->expectedOutput(), $this->runBin('bin/jit.php'));
    }

    public function testVmStrictTypesTypeError(): void
    {
        $repo = dirname(__DIR__, 2);
        $code = <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $dll = new SplDoublyLinkedList();
    $dll->push('a');
    $dll->offsetGet(null);
    echo "noerror\n";
} catch (TypeError $e) {
    echo "EX:" . $e->getMessage() . "\n";
}
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31804s_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, $stdout.$stderr);
        $this->assertSame(
            "EX:SplDoublyLinkedList::offsetGet(): Argument #1 (\$index) must be of type int, null given\n",
            $stdout
        );
    }

    private function expectedOutput(): string
    {
        $dep = 'ERR:E_DEPRECATED:SplDoublyLinkedList::%s(): Passing null to parameter #1 ($index) of type int is deprecated';

        return sprintf($dep, 'offsetGet')."\n"
            ."get=a\n"
            .sprintf($dep, 'offsetExists')."\n"
            ."exists=true\n"
            .sprintf($dep, 'offsetUnset')."\n"
            ."count=1 top0=b\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $probe = $repo.'/test/repro/maintainer_gap_dllist_offset_null.php';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $probe],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
