<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT session_start with default save path (no PHP_COMPILER_SESSION_DIR) (#32963).
 *
 * @group llvm
 * @group aot
 */
final class SessionStartDefaultPathAotTest extends TestCase
{
    public function testSessionStartWithoutCompilerSessionDirEnv(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $repo = dirname(__DIR__, 2);
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_aot_sess_default_');
        $this->assertNotFalse($outfile);
        @unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        unset($env['PHP_COMPILER_SESSION_DIR'], $env['HTTP_COOKIE'], $env['GATEWAY_INTERFACE'], $env['REQUEST_METHOD']);
        putenv('PHP_COMPILER_SESSION_DIR');
        putenv('HTTP_COOKIE');
        putenv('GATEWAY_INTERFACE');
        putenv('REQUEST_METHOD');

        $code = <<<'PHP'
<?php
session_start();
echo "ok\n";
PHP;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $outfile],
            $descriptorSpec,
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileExit = proc_close($compile);
        $this->assertSame(0, $compileExit, trim($compileErr !== false ? $compileErr : ''));
        $this->assertFileExists($outfile);

        $run = proc_open(
            [$outfile],
            $descriptorSpec,
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
        $runExit = proc_close($run);
        @unlink($outfile);

        $combined = ($stdout !== false ? $stdout : '').($stderr !== false ? $stderr : '');
        $this->assertSame(0, $runExit, $combined);
        $this->assertStringContainsString('ok', $combined);
        $this->assertStringNotContainsString('fatal signal', $combined);
    }
}
