<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile gate for typed array property assign/fetch (issue #58).
 *
 * Native execution of property array offsets is still tracked separately; this
 * test ensures LLVM verify and link succeed for the pattern.
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class ObjectPropertyArrayAotCompileTest extends TestCase
{
    public function testObjectPropertyArrayOffsetPhptCompiles(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
class ConfigHolder {
    private array $config;

    public function run(): void
    {
        $this->config = ['app_name' => 'AOT'];
        echo $this->config['app_name'], "\n";
    }
}

(new ConfigHolder())->run();
PHP;

        $this->assertCompileExitZero($source, 'typed array property echo');
    }

    public function testObjectPropertyArrayReturnStringMethodCompiles(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
class ConfigHolder {
    private array $config;
    public function run(): void {
        $this->config = ['app_name' => 'AOT'];
        echo $this->name(), "\n";
    }
    private function name(): string {
        return $this->config['app_name'];
    }
}
(new ConfigHolder())->run();
PHP;

        $this->assertCompileExitZero($source, 'array property return string method');
    }

    public function testObjectPropertyArrayAssignAndFetchExecutes(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
class ConfigHolder {
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function run(): void {
        echo $this->config['app_name'], "\n";
    }
}

$config = ['app_name' => 'AOT'];
(new ConfigHolder($config))->run();
PHP;

        $this->assertExecuteStdoutContains($source, 'AOT', 'typed array property via constructor');
    }

    public function testObjectPropertyArrayPassedToMethodExecutes(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
class ConfigHolder {
    private array $config;

    public function setConfig(array $config): void {
        $this->config = $config;
    }

    public function run(): void {
        echo $this->config['app_name'], "\n";
    }
}

$holder = new ConfigHolder();
$config = ['app_name' => 'AOT'];
$holder->setConfig($config);
$holder->run();
PHP;

        $this->assertExecuteStdoutContains($source, 'AOT', 'typed array property via method arg');
    }

    private function assertExecuteStdoutContains(string $source, string $needle, string $label): void
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'phpc_prop_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_prop_aot_');
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

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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

    private function assertCompileExitZero(string $source, string $label): void
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'phpc_prop_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_prop_aot_');
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

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        @unlink($outfile);
        @unlink($sourcePath);

        $this->assertSame(
            0,
            $exitCode,
            $label.' AOT compile failed: '.trim($stderr !== false ? $stderr : '')
        );
    }
}
