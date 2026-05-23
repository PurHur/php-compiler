<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * phpc build --project actionable errors when user-class AOT is blocked (#643).
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

    public function testTrailerContainsIssue764AndGuidance(): void
    {
        $trailer = PhpcBuild::formatUserClassTrailer();
        $this->assertStringContainsString('#764', $trailer);
        $this->assertStringContainsString('user-class projects', $trailer);
        $this->assertStringContainsString('phpc lint', $trailer);
        $this->assertStringContainsString('phpc serve', $trailer);
        $this->assertStringContainsString('miniwebapp-gates', $trailer);
        $this->assertStringContainsString('compile-unit graph', $trailer);
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
        $this->assertStringContainsString('#764', $out);
    }

    public function testEmitBuildOutputKeepsLlvmStderrWhenVerbose(): void
    {
        $out = $this->captureEmitBuildOutput(
            "Basic Block in function 'router::renderhome' does not have terminator!\n",
            true
        );
        $this->assertStringContainsString('terminator', $out);
        $this->assertStringContainsString('#764', $out);
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
                '003-MiniWebApp native AOT execute blocked (#764): '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));
        $this->assertStringNotContainsString('user-defined classes are not yet linkable', $stderr);
        $binary = $repoRoot.'/examples/003-MiniWebApp/.phpc/bin/app';
        $this->assertFileExists($binary);
    }

    private function captureEmitBuildOutput(string $compileStderr, bool $verbose): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $snippet = sprintf(
            'require %s; \PHPCompiler\Cli\PhpcBuild::emitBuildOutput(%s, %s);',
            var_export($repoRoot.'/vendor/autoload.php', true),
            var_export(['exit' => 1, 'stdout' => '', 'stderr' => $compileStderr], true),
            $verbose ? 'true' : 'false'
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
