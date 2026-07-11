<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/** VM compliance for Closure::bind() on internal class scope (#5011). */
class ClosureBindInternalClassVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_bind_internal_class.phpt' => self::parsePHPT(
            __DIR__ . '/cases/language/closure_bind_internal_class.phpt',
            'closure_bind_internal_class.phpt'
        );
        yield 'closure_bindto_internal_class.phpt' => self::parsePHPT(
            __DIR__ . '/cases/language/closure_bindto_internal_class.phpt',
            'closure_bindto_internal_class.phpt'
        );
        yield 'closure_bindto_internal_instance.phpt' => self::parsePHPT(
            __DIR__ . '/cases/language/closure_bindto_internal_instance.phpt',
            'closure_bindto_internal_instance.phpt'
        );
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

        $vmCmd = array_merge($this->phpCommand(), [$this->BIN]);
        $cmd = array_merge(self::llvmEnvPrefix(), $vmCmd);
        $result = self::runVmSubprocessWithStderr($cmd, $repoRoot, $env, $code, $name);
        $this->assertExpect($result, $sections);
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

        $stderrTrim = trim((string) $stderr);
        $stdoutTrim = trim((string) $stdout);
        if ('' === $stderrTrim) {
            return $stdoutTrim;
        }
        if ('' === $stdoutTrim) {
            return $stderrTrim;
        }

        return $stderrTrim . "\n" . $stdoutTrim;
    }
}
