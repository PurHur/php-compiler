<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for class member constants (#2199).
 *
 * Uses the VisibilityTest subprocess pattern (stdin, stderr merged) so phpt
 * expectations are not tied to proc_close exit quirks under env-wrapped LLVM.
 */
class ClassMemberConstVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'class_member_const.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_member_const.phpt',
            'class_member_const.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyLlvmToolchainEnv($env);
        self::applyEnvSection($env, $sections);
        PhptWebSections::applyToEnv($env, $sections);
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);

        $runfile = isset($sections['RUNFILE']) ? trim($sections['RUNFILE']) : '';
        if ('' !== $runfile) {
            $runPath = realpath(($sections['__phpt_dir'] ?? $repoRoot).'/'.$runfile);
            if (false === $runPath) {
                $this->fail("RUNFILE not found: {$runfile}");
            }
            $vmCmd = array_merge($this->phpCommand(), [$this->BIN, $runPath]);
            $cwd = dirname($runPath);
            $stdin = null;
        } else {
            $vmCmd = array_merge($this->phpCommand(), [$this->BIN]);
            $cwd = $repoRoot;
            $stdin = $code;
        }

        $cmd = array_merge(self::llvmEnvPrefix(), $vmCmd);
        $result = self::runVmSubprocessWithStderr($cmd, $cwd, $env, $stdin, $name);
        $this->assertExpect($this->stripPhpNoise($result), $sections);
    }

    protected function stripPhpNoise(string $output): string
    {
        $lines = explode("\n", $output);
        $filtered = array_filter(
            $lines,
            static fn (string $line): bool => !preg_match(
                '/^(PHP Warning|PHP Notice|PHP Deprecated):/',
                $line
            )
        );

        return implode("\n", $filtered);
    }

    /**
     * @param list<string>  $cmd
     * @param array<string, string> $env
     */
    protected static function runVmSubprocessWithStderr(array $cmd, string $cwd, array $env, ?string $stdin, string $testName): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException("Failed to spawn VM for test: {$testName}");
        }
        if (null !== $stdin) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);

        return trim((string) $stdout)."\n".trim((string) $stderr);
    }
}
