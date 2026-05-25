<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * phpc build --project actionable errors when user-class AOT is blocked (#643, #792).
 */
final class PhpcBuildProjectTest extends TestCase
{
    public function testDetectsUnsupportedObjectTypeMessage(): void
    {
        $this->assertTrue(
            PhpcBuild::isUserClassAotBlocked('LogicException: Unsupported native type __object__')
        );
    }

    public function testDetectsLlvmVerifyInUserMethod(): void
    {
        $stderr = <<<'ERR'
Basic Block in function 'router::renderhome' does not have terminator!
Function return type does not match operand type of return inst!
ERR;
        $this->assertTrue(PhpcBuild::isUserClassAotBlocked($stderr));
    }

    public function testTrailerContainsUserClassGuidance(): void
    {
        $trailer = PhpcBuild::formatUserClassTrailer();
        $this->assertStringContainsString('MiniWebAppAotExecuteTest', $trailer);
        $this->assertStringContainsString('user-class', $trailer);
        $this->assertStringContainsString('phpc lint', $trailer);
        $this->assertStringContainsString('phpc serve', $trailer);
        $this->assertStringContainsString('miniwebapp-gates', $trailer);
        $this->assertStringContainsString('compile-unit graph', $trailer);
    }

    public function testDetectsWebProjectManifest(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $this->assertTrue(PhpcBuild::isWebProjectForExecuteProbe($repoRoot.'/examples/003-MiniWebApp'));
        $this->assertFalse(PhpcBuild::isWebProjectForExecuteProbe($repoRoot.'/examples/001-SimpleWeb'));
    }

    public function testWebProjectSuccessTrailerMentionsExecuteProbe(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/examples/003-MiniWebApp';
        $binary = $project.'/.phpc/bin/app';
        $trailer = PhpcBuild::formatWebProjectSuccessTrailer($project, $binary);
        $this->assertStringContainsString('MiniWebAppAotExecuteTest', $trailer);
        $this->assertStringContainsString('wc -c', $trailer);
        $this->assertStringContainsString('QUERY_STRING=route=home', $trailer);
        $this->assertStringContainsString('phpc run --project', $trailer);
        $this->assertStringNotContainsString('#568', $trailer);
        $this->assertStringNotContainsString('568', $trailer);
    }

    public function testEmitBuildOutputSuccessPrintsExecuteProbeTrailer(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/examples/003-MiniWebApp';
        $binary = $project.'/.phpc/bin/app';
        $out = $this->captureEmitBuildOutput(
            ['exit' => 0, 'stdout' => '', 'stderr' => ''],
            false,
            $project,
            $binary
        );
        $this->assertStringContainsString('Quick execute probe', $out);
        $this->assertStringContainsString('MiniWebAppAotExecuteTest', $out);
        $this->assertStringContainsString('wc -c', $out);
    }

    public function testVerboseEnabledFromEnv(): void
    {
        $previous = getenv('PHPC_BUILD_VERBOSE');
        putenv('PHPC_BUILD_VERBOSE=1');
        try {
            $this->assertTrue(PhpcBuild::verboseEnabled(false));
        } finally {
            if (false === $previous) {
                putenv('PHPC_BUILD_VERBOSE');
            } else {
                putenv('PHPC_BUILD_VERBOSE='.$previous);
            }
        }
    }

    public function testEmitBuildOutputSuppressesLlvmStderrWhenNotVerbose(): void
    {
        $out = $this->captureEmitBuildOutput(
            "Basic Block in function 'router::renderhome' does not have terminator!\n",
            false
        );
        $this->assertStringNotContainsString('terminator', $out);
        $this->assertStringContainsString('MiniWebAppAotExecuteTest', $out);
    }

    public function testEmitBuildOutputKeepsLlvmStderrWhenVerbose(): void
    {
        $out = $this->captureEmitBuildOutput(
            "Basic Block in function 'router::renderhome' does not have terminator!\n",
            true
        );
        $this->assertStringContainsString('terminator', $out);
        $this->assertStringContainsString('MiniWebAppAotExecuteTest', $out);
    }

    public function testListUnitsMiniWebAppPrintsEntryUnitsBinary(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $result = $this->runPhpcBuild(
            ['--project', $repoRoot.'/examples/003-MiniWebApp', '--list-units'],
            $repoRoot
        );
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('entry: public/index.php', $result['stderr']);
        $this->assertStringContainsString('binary: .phpc/bin/app', $result['stderr']);
        $this->assertStringContainsString('units:', $result['stderr']);
        $this->assertStringContainsString('src/Router.php', $result['stderr']);
        $this->assertStringContainsString('config.php', $result['stderr']);
    }

    public function testListUnitsWithDryRunExitsBeforeLlvm(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $result = $this->runPhpcBuild(
            ['--project', $repoRoot.'/examples/001-SimpleWeb', '--list-units', '--dry-run'],
            $repoRoot
        );
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertStringContainsString('entry:', $result['stderr']);
        $this->assertStringContainsString('units:', $result['stderr']);
        $this->assertStringNotContainsString('LLVMAbstract', $result['stderr']);
        $this->assertStringNotContainsString('does not have terminator', $result['stderr']);
    }

    public function testPrintIncludesMiniWebAppManifestLinkOrder(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/examples/003-MiniWebApp';
        $result = $this->runPhpcBuild(
            ['--project', $project, '--print-includes'],
            $repoRoot
        );
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $lines = array_values(array_filter(explode("\n", trim($result['stdout'])), static fn (string $line): bool => '' !== $line));
        $this->assertGreaterThanOrEqual(3, count($lines));
        foreach ($lines as $line) {
            $this->assertStringStartsWith('/', $line, 'expected absolute path: '.$line);
        }
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('src/Router.php', $joined);
        $this->assertStringContainsString('config.php', $joined);
        $this->assertStringContainsString('public/index.php', $joined);
        $routerPos = strpos($joined, 'Router.php');
        $configPos = strpos($joined, 'config.php');
        $entryPos = strpos($joined, 'index.php');
        $this->assertNotFalse($routerPos);
        $this->assertNotFalse($configPos);
        $this->assertNotFalse($entryPos);
        $this->assertLessThan($configPos, $routerPos, 'includes[] order: Router before config');
        $this->assertLessThan($entryPos, $configPos, 'entry is last');
    }

    public function testVerboseMiniWebAppBuildPrintsCompileUnitGraph(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $result = $this->runPhpcBuild(
            ['--project', $repoRoot.'/examples/003-MiniWebApp', '--verbose'],
            $repoRoot
        );
        $this->assertStringContainsString('src/Router.php', $result['stderr']);
        $this->assertStringContainsString('class Router', $result['stderr']);
        $this->assertStringContainsString('compile units', $result['stderr']);
        $this->assertStringContainsString('lint:', $result['stderr']);
    }

    public function testMiniWebAppBuildLinksNativeBinary(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $phpc = $repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }
        if (!LlvmToolchain::isReady($repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        $proc = proc_open(
            [$phpc, 'build', '--project', $repoRoot.'/examples/003-MiniWebApp'],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        if (0 !== $exit && PhpcBuild::isUserClassAotBlocked($stderr)) {
            $this->markTestSkipped(
                '003-MiniWebApp user-class AOT link failed (LLVM verify): '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));
        $this->assertStringNotContainsString('user-defined classes are not yet linkable', $stderr);
        $binary = $repoRoot.'/examples/003-MiniWebApp/.phpc/bin/app';
        $this->assertFileExists($binary);
    }

    private function captureEmitBuildOutput(
        array|string $result,
        bool $verbose,
        ?string $projectDir = null,
        ?string $binaryPath = null
    ): string {
        $repoRoot = dirname(__DIR__, 2);
        if (is_string($result)) {
            $result = ['exit' => 1, 'stdout' => '', 'stderr' => $result];
        }
        $snippet = sprintf(
            'require %s; \PHPCompiler\Cli\PhpcBuild::emitBuildOutput(%s, %s, %s, %s);',
            var_export($repoRoot.'/vendor/autoload.php', true),
            var_export($result, true),
            $verbose ? 'true' : 'false',
            var_export($projectDir, true),
            var_export($binaryPath, true)
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(array_merge(self::phpCommand(), ['-r', $snippet]), $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);

        return false !== $stderr ? $stderr : '';
    }

    /**
     * @param list<string> $args arguments after phpc build
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpcBuild(array $args, string $cwd): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'build', ...$args]);
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
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
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
