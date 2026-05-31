<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT anonymous class method lowering when bin/compile.php sets PHP_COMPILER_SELFHOST_AOT=1 (#3098).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class AnonymousClassAotSelfHostTest extends TestCase
{
    public function testAnonymousClassMethodCallWithSelfHostAotEnv(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
$o = new class {
    public function id(): int {
        return 7;
    }
};
echo $o->id(), "\n";
PHP;

        $this->assertAotCompileAndExecute($source, '7', 'anonymous class with SELFHOST_AOT=1');
    }

    private function assertAotCompileAndExecute(string $source, string $needle, string $label): void
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'phpc_anon_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_anon_aot_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        $env['PHP_COMPILER_SELFHOST_AOT'] = '1';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $this->assertSame(
            0,
            $exitCode,
            $label.' AOT compile failed: '.trim($stderr !== false ? $stderr : '')
        );

        $run = proc_open([$outfile], $descriptorSpec, $runPipes, $repoRoot, $env);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($outfile);
        @unlink($sourcePath);

        $this->assertSame(
            0,
            $runCode,
            $label.' AOT execute failed: '.trim($runErr !== false ? $runErr : '')
        );
        $this->assertStringContainsString(
            $needle,
            $stdout !== false ? $stdout : '',
            $label.' stdout'
        );
    }
}
