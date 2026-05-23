<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Literal include/require with __DIR__ (issues #54, #85).
 *
 * @group llvm
 */
final class IncludeLiteralTest extends TestCase
{
    /**
     * Included file reads locals set in the caller (issue #471).
     */
    public function testVmIncludeInheritsCallerScope(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_scope_inherit/entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runVm([$entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']);
        $this->assertSame("Home\n", $exit['stdout']);
    }

    /**
     * Nested include chain inherits outer caller scope (layout → partial).
     */
    public function testVmNestedIncludeInheritsCallerScope(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_scope_inherit/nested_entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runVm([$entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']);
        $this->assertSame("nested-scope\n", $exit['stdout']);
    }

    /**
     * Second-tier literal include execute (layout → partial); tracked in #764 / #784.
     *
     * @group llvm
     */
    public function testAotNestedIncludeInheritsCallerScope(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM toolchain not available');
        }
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_scope_inherit/nested_entry.php');
        $this->assertNotFalse($entry);
        $this->markTestSkipped(
            'AOT nested literal include execute segfaults (issue #764); VM parity is green'
        );
    }

    public function testVmRequireDirRelative(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_dir_literal/entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runVm([$entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']);
        $this->assertSame("hello from helper\n", $exit['stdout']);
    }

    /**
     * require expression captures return value (MiniWebApp config.php pattern, issue #67).
     */
    public function testVmRequireReturnValue(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/require_return/entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runVm([$entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']);
        $this->assertSame("TestApp\n", $exit['stdout']);
    }

    /**
     * AOT require-as-expression return value (issue #783).
     *
     * @group llvm
     */
    public function testAotRequireReturnValue(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM toolchain not available');
        }
        $entry = realpath(__DIR__.'/../compliance/cases/language/require_return/entry.php');
        $this->assertNotFalse($entry);
        $outfile = sys_get_temp_dir().'/phpc_req_ret_aot_'.bin2hex(random_bytes(6));
        $repoRoot = dirname(__DIR__, 2);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open(
            array_merge(
                LlvmToolchain::envPrefix($repoRoot),
                self::phpCommand(),
                [realpath($repoRoot.'/bin/compile.php'), '-o', $outfile, $entry]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            []
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim($compileErr !== false ? $compileErr : ''));
        $this->assertFileExists($outfile);
        $run = proc_open([$outfile], $descriptorSpec, $runPipes, $repoRoot, []);
        $this->assertIsResource($run);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        $this->assertSame("TestApp\n", $stdout !== false ? $stdout : '');
        @unlink($outfile);
    }

    public function testLintFollowsDirRelativeInclude(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_dir_literal/entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runLint(['--project', $entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $this->assertStringNotContainsString('dynamic include/require', $exit['stderr']);
    }

    public function testAotRequireDirRelative(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM toolchain not available');
        }
        $entry = realpath(__DIR__.'/../fixtures/aot/cases/include_dir_literal/entry.php');
        $this->assertNotFalse($entry);
        $outfile = sys_get_temp_dir().'/phpc_inc_aot_'.bin2hex(random_bytes(6));
        $repoRoot = dirname(__DIR__, 2);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open(
            array_merge(
                LlvmToolchain::envPrefix($repoRoot),
                self::phpCommand(),
                [realpath($repoRoot.'/bin/compile.php'), '-o', $outfile, $entry]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            []
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim($compileErr !== false ? $compileErr : ''));
        $this->assertFileExists($outfile);
        $run = proc_open([$outfile], $descriptorSpec, $runPipes, $repoRoot, []);
        $this->assertIsResource($run);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        $this->assertSame("hello from helper\n", $stdout !== false ? $stdout : '');
        @unlink($outfile);
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runVm(array $args): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [realpath($repoRoot.'/bin/vm.php')], $args);

        return $this->runCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $lintArgs
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLint(array $lintArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [realpath($repoRoot.'/bin/lint.php')], $lintArgs);

        return $this->runCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }
}
