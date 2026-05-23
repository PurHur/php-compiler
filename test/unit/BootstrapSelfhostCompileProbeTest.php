<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Self-host compile probe script (issue #816).
 */
final class BootstrapSelfhostCompileProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root.'/script/bootstrap-lib.php';
    }

    public function testJitProgressNoteAndRead(): void
    {
        $path = sys_get_temp_dir().'/php-compiler-jit-progress-'.getmypid();
        putenv('PHP_COMPILER_JIT_PROGRESS_FILE='.$path);
        try {
            \PHPCompiler\JIT\Progress::noteFunction('PHPCompiler\\Compiler::compile');
            $this->assertSame('PHPCompiler\\Compiler::compile', \PHPCompiler\JIT\Progress::readLast($path));
            $this->assertSame(
                'PHPCompiler\\Compiler::compile',
                \bootstrapSelfhostProbeLastJitFunc($path)
            );
        } finally {
            @unlink($path);
            putenv('PHP_COMPILER_JIT_PROGRESS_FILE');
        }
    }

    public function testSelfhostScriptsExportDedicatedAotEnv(): void
    {
        $link = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-link.sh');
        $probe = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compile-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $link);
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $probe);
        $this->assertStringContainsString('PHP_COMPILER_JIT_PROGRESS_FILE', $probe);
        $this->assertStringContainsString('PHP_COMPILER_JIT_PROGRESS_FILE', $link);
    }

    public function testExtractNextLowerIgnoresNoticeAndDeprecated(): void
    {
        $output = <<<'OUT'
PHP Notice:  Undefined variable: x in /tmp/a.php on line 1
PHP Deprecated:  Constant FOO is deprecated in /tmp/b.php on line 2
LogicException: unsupported CFG op
OUT;

        $this->assertSame('unsupported CFG op', \bootstrapSelfhostProbeExtractNextLower($output));
    }

    public function testExtractNextLowerParseError(): void
    {
        $output = <<<'OUT'
PHP Notice:  Undefined variable: x in /tmp/a.php on line 1
PHP Parse error:  syntax error, unexpected token "}" in /tmp/b.php on line 3
OUT;

        $this->assertSame(
            'syntax error, unexpected token "}" in /tmp/b.php on line 3',
            \bootstrapSelfhostProbeExtractNextLower($output)
        );
    }

    public function testExtractNextLowerUncaughtError(): void
    {
        $output = <<<'OUT'
PHP Deprecated:  Using ${var} in strings is deprecated in /tmp/a.php on line 1
Uncaught Error: Call to undefined function foo() in /tmp/b.php:10
Stack trace:
#0 {main}
OUT;

        $this->assertSame(
            'Call to undefined function foo() in /tmp/b.php:10',
            \bootstrapSelfhostProbeExtractNextLower($output)
        );
    }

    public function testExtractNextLowerFatalError(): void
    {
        $output = 'PHP Fatal error:  Maximum execution time exceeded in /tmp/a.php on line 1';

        $this->assertSame(
            'Maximum execution time exceeded in /tmp/a.php on line 1',
            \bootstrapSelfhostProbeExtractNextLower($output)
        );
    }

    public function testSegfaultNextLowerIncludesLastJitFunc(): void
    {
        $path = sys_get_temp_dir().'/php-compiler-jit-progress-segfault-'.getmypid();
        file_put_contents($path, 'PHPCompiler\\JIT\\Result::getFunc');
        try {
            $last = \bootstrapSelfhostProbeLastJitFunc($path);
            $this->assertSame('PHPCompiler\\JIT\\Result::getFunc', $last);
            $next = 'LLVM segfault during native compile (exit 139)';
            if (null !== $last) {
                $next .= ' (last JIT: '.$last.')';
            }
            $this->assertStringContainsString('last JIT: PHPCompiler\\JIT\\Result::getFunc', $next);
        } finally {
            @unlink($path);
        }
    }

    public function testProbeScriptSetsDefaultJitProgressFile(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compile-probe.php');
        $this->assertStringContainsString("build/.last-jit-func", $script);
        $this->assertStringContainsString('LAST_JIT_FUNC:', $script);
    }

    public function testShellProbePrintsLastJitFuncOnSegfault(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compile-probe.sh';
        $this->assertFileExists($script);

        $fakePhpDir = sys_get_temp_dir().'/php-compiler-fake-php-'.getmypid();
        if (!is_dir($fakePhpDir)) {
            mkdir($fakePhpDir, 0775, true);
        }
        $fakePhp = $fakePhpDir.'/php';
        file_put_contents($fakePhp, <<<'SH'
#!/usr/bin/env bash
if [[ -n "${PHP_COMPILER_JIT_PROGRESS_FILE:-}" ]]; then
  printf '%s' 'PHPCompiler\Compiler::compile' > "${PHP_COMPILER_JIT_PROGRESS_FILE}"
fi
exit 139
SH
        );
        chmod($fakePhp, 0755);

        try {
            $cmd = 'PATH='.escapeshellarg($fakePhpDir.':'.getenv('PATH'))
                .' bash '.escapeshellarg($script).' 2>&1';
            $out = shell_exec($cmd);
            $this->assertIsString($out);
            $this->assertStringContainsString('LAST_JIT_FUNC: PHPCompiler\\Compiler::compile', $out);
        } finally {
            @unlink($fakePhp);
            @rmdir($fakePhpDir);
        }
    }

    public function testProbeScriptPrintsNextLowerOnFailure(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compile-probe.php';
        $this->assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';
        $out = shell_exec($cmd);
        $this->assertIsString($out);
        if (str_contains($out, 'bootstrap-selfhost-compile-probe: OK')) {
            $this->markTestSkipped('Self-host compile probe succeeded; NEXT_LOWER not emitted.');
        }
        $this->assertStringContainsString('NEXT_LOWER:', $out);
    }
}
