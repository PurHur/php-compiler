<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT public private(set) write Error must use Zend "from global scope" wording (#26873).
 *
 * NestedJIT must restore jitEnclosingBlock so CloneWithJitHelper is not reported as caller.
 *
 * @group llvm
 * @group aot
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class AsymmetricPrivateSetAot26873Test extends TestCase
{
    public function testAotPrivateSetWriteErrorMatchesGlobalScopeWording(): void
    {
        $repo = \realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        \putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility requires PHP_COMPILER_PROFILE≥8.4 (#26873)');
        }
        if (!LlvmToolchain::isReady($repo)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #26873 AOT private(set) needs LLVM');
        }

        $source = $repo.'/test/repro/maintainer_gap_aot_asym_private_set.php';
        $this->assertFileExists($source);
        $out = $repo.'/build/test-aot-asym-private-set-26873';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        LlvmToolchain::applyProcessEnv($env, $repo);

        $cmd = \array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source]
        );
        $proc = \proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        $this->assertSame(0, $exit, \trim($stdout."\n".$stderr));
        $this->assertFileExists($out);
        $this->assertTrue(\is_executable($out));

        $run = \proc_open(
            [$out],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo
        );
        $this->assertIsResource($run);
        $runOut = \stream_get_contents($runPipes[1]);
        $runErr = \stream_get_contents($runPipes[2]);
        \fclose($runPipes[1]);
        \fclose($runPipes[2]);
        $runExit = \proc_close($run);
        @\unlink($out);

        $this->assertSame(0, $runExit, \trim($runOut."\n".$runErr));
        $this->assertSame(
            "read=a\n"
            ."catch=Error:Cannot modify private(set) property C::\$x from global scope\n"
            ."done\n",
            $runOut
        );
        $this->assertStringNotContainsString('CloneWithJitHelper', $runOut);
    }
}
