<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * AOT nested nullsafe ?-> + ?? — issue #26818.
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class NullsafeNestedAot26818Test extends TestCase
{
    public function testAotNestedNullsafeCoalescePrintsNPipe5(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        if (!LlvmToolchain::isReady($repo)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #26818 AOT nullsafe needs LLVM');
        }

        $source = $repo.'/test/repro/nullsafe_nested_aot_26818.php';
        $this->assertFileExists($source);
        $out = $repo.'/build/test-nullsafe-nested-aot-26818';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);

        $cmd = array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source]
        );
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim($stdout."\n".$stderr));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));

        $run = proc_open(
            [$out],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo
        );
        $this->assertIsResource($run);
        $runOut = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runExit = proc_close($run);

        $this->assertSame(0, $runExit, trim($runOut."\n".$runErr));
        $this->assertSame("n|5\n", $runOut);
    }
}
