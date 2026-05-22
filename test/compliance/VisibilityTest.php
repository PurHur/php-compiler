<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * Method visibility enforcement (issue #588).
 */
class VisibilityTest extends BaseTest
{
    public static function providePHPTests(): \Generator
    {
        yield from self::providePHPTestsFromDir(__DIR__ . '/cases/language/visibility');
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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
        $vmCmd = array_merge($this->phpCommand(), [$this->BIN]);
        $cmd = array_merge(self::llvmEnvPrefix(), $vmCmd);
        $result = self::runVmSubprocessWithStderr($cmd, $repoRoot, $env, $code, $name);
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

        return trim((string) $stdout) . "\n" . trim((string) $stderr);
    }
}
