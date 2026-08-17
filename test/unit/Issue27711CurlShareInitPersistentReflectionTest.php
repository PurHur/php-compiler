<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** curl_share_init_persistent() Reflection + named share_options (#27711). */
final class Issue27711CurlShareInitPersistentReflectionTest extends TestCase
{
    public function testVm(): void
    {
        $this->assertSame($this->expected(), $this->runBin('bin/vm.php'));
    }

    private function expected(): string
    {
        return "arity=1\n"
            ."param=share_options:array\n"
            ."ret=CurlSharePersistentHandle\n"
            ."named_ok=CurlSharePersistentHandle\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $probe = $repo.'/test/repro/issue_27711_curl_share_init_persistent_reflection.php';
        $env = $_ENV;
        $env['PHP_COMPILER_PROFILE'] = '8.5';
        $env['PHP_COMPILER_ENABLE_CURL'] = '1';
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
