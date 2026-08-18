<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** `@` error-control operator VM smoke (issue #3546). */
final class ErrorControlOperatorTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'error_control_operator.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/error_control_operator.phpt',
            'error_control_operator.phpt'
        );
        yield 'at_silence_assign_undef_rhs.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/at_silence_assign_undef_rhs.phpt',
            'at_silence_assign_undef_rhs.phpt'
        );
        yield 'at_silence_value_undef.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/at_silence_value_undef.phpt',
            'at_silence_value_undef.phpt'
        );
        yield 'at_silence_undef_closure_error_get_last.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/at_silence_undef_closure_error_get_last.phpt',
            'at_silence_undef_closure_error_get_last.phpt'
        );
    }

    /** Issue #29132 — `$a = @$undef` must not print Undefined variable on stderr. */
    public function testAssignUndefRhsSilenceLeavesStderrEmpty(): void
    {
        $code = <<<'PHP'
        <?php
        error_reporting(E_ALL);
        $a = @$undef_assign_rhs_29132_stderr;
        echo "ok\n";
        PHP;
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyLlvmToolchainEnv($env);
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
        $cmd = array_merge(self::llvmEnvPrefix(), $this->phpCommand(), [$this->BIN]);
        [$stdout, $exitCode, $stderr] = self::runVmSubprocess($cmd, $repoRoot, $env, $code, __FUNCTION__);
        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringNotContainsString('Undefined variable', $stderr);
    }

    /** Issue #31881 — echo / arithmetic / call-arg `@$undef` must not print Undefined variable. */
    public function testValueConsumingUndefSilenceLeavesStderrEmpty(): void
    {
        $code = <<<'PHP'
        <?php
        error_reporting(E_ALL);
        echo @$undef_echo_31881;
        echo @$undef_plus_31881 + 1;
        strlen(@$undef_call_31881);
        echo "ok\n";
        PHP;
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyLlvmToolchainEnv($env);
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
        $cmd = array_merge(self::llvmEnvPrefix(), $this->phpCommand(), [$this->BIN]);
        [$stdout, $exitCode, $stderr] = self::runVmSubprocess($cmd, $repoRoot, $env, $code, __FUNCTION__);
        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringNotContainsString('Undefined variable', $stderr);
        $this->assertStringContainsString('ok', $stdout);
    }

    /** Issue #32041 — `@$undef` inside a closure records error_get_last without printing. */
    public function testSilenceUndefInsideClosureRecordsLastError(): void
    {
        $code = <<<'PHP'
        <?php
        error_reporting(E_ALL);
        error_clear_last();
        $fn = function () {
            @$undef_32041;
            $e = error_get_last();
            echo 'type=', $e['type'] ?? 'none', "\n";
            echo 'msg=', $e['message'] ?? 'none', "\n";
        };
        $fn();
        PHP;
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        self::applyLlvmToolchainEnv($env);
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
        $cmd = array_merge(self::llvmEnvPrefix(), $this->phpCommand(), [$this->BIN]);
        [$stdout, $exitCode, $stderr] = self::runVmSubprocess($cmd, $repoRoot, $env, $code, __FUNCTION__);
        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringNotContainsString('Undefined variable', $stderr);
        $this->assertStringContainsString('type=2', $stdout);
        $this->assertStringContainsString('Undefined variable $undef_32041', $stdout);
    }
}
