<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * JIT compliance for PHP 8.2 dynamic property deprecation (#4570).
 *
 * MCJIT execute for undeclared property writes; LLVM lowering emits
 * __compiler_trigger_error(E_DEPRECATED) via DynamicPropertyDeprecationGuard (#5111).
 *
 * @group llvm
 * @group jit
 */
final class DynamicPropertyDeprecatedJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dynamic_property_deprecation.phpt' => self::parsePHPT(
            __DIR__ . '/cases/language/dynamic_property_deprecation.phpt',
            'dynamic_property_deprecation.phpt'
        );
        yield 'dynamic_property_deprecation_line.phpt' => self::parsePHPT(
            __DIR__ . '/cases/language/dynamic_property_deprecation_line.phpt',
            'dynamic_property_deprecation_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
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

        $jitCmd = array_merge($this->phpCommand(), [$this->BIN]);
        $cmd = array_merge(self::llvmEnvPrefix(), $jitCmd);
        [$result, $exitCode, $stderr] = self::runVmSubprocess($cmd, $repoRoot, $env, $code, $name);
        if (0 !== $exitCode) {
            $detail = trim($stderr);
            if ('' === $detail) {
                $detail = '(no stderr)';
            }
            $this->fail("JIT exited with code {$exitCode} for {$name}: {$detail}");
        }
        $stderrTrim = trim($stderr);
        $stdoutTrim = trim($result);
        if ('' === $stderrTrim) {
            $merged = $stdoutTrim;
        } elseif ('' === $stdoutTrim) {
            $merged = $stderrTrim;
        } else {
            $merged = $stderrTrim . "\n" . $stdoutTrim;
        }
        $this->assertExpect($merged, $sections);
    }
}
