<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT must return exit 0 after reading $argv — not segfault (#36195).
 *
 * @group aot-lint
 */
final class CliArgvAotExitTest extends TestCase
{
    public function testCliArgvGlobalsMarkedScriptGlobalSlot(): void
    {
        $init = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString('scriptGlobalSlot = true', $init);
    }

    public function testArgvReadReturnsZeroExitStatus(): void
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_argv_aot_');
        $src = $tmp.'.php';
        $out = $tmp.'_bin';
        file_put_contents($src, <<<'PHP'
<?php
echo $argv[1], "\n";
PHP);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $src],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), $compileErr ?: 'compile failed');

        $run = proc_open(
            [$out, 'hello'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $runErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($run), $runErr ?: 'AOT argv binary segfaulted on return');
        $this->assertSame("hello\n", $stdout);

        @unlink($src);
        @unlink($out);
        @unlink($tmp);
    }
}
