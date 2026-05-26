<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc serve --jit e2e smoke harness (issue #2274).
 */
final class ExamplesServeJitSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testMakefileHasExamplesServeJitSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('examples-serve-jit-smoke:', $makefile);
        $this->assertStringContainsString('examples-serve-jit-smoke.sh', $makefile);
        $this->assertStringContainsString('SERVE_JIT_SMOKE_GATE=1', $makefile);
    }

    public function testExamplesServeJitSmokeScriptExists(): void
    {
        $script = self::$root.'/script/examples-serve-jit-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('SERVE_JIT_SMOKE_GATE', $body);
        $this->assertStringContainsString('phpc serve --jit', $body);
        $this->assertStringContainsString('001-SimpleWeb', $body);
        $this->assertStringContainsString('003-MiniWebApp', $body);
        $this->assertStringContainsString('#475', $body);
        $this->assertStringContainsString('jit-runtime-probe.php', $body);
    }

    public function testCiDefaultsDefinesServeJitSmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SERVE_JIT_SMOKE_GATE="${SERVE_JIT_SMOKE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2274', $defaults);
    }

    public function testCiCommonDefinesServeJitSmokeRunner(): void
    {
        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_examples_serve_jit_smoke()', $common);
        $this->assertStringContainsString('examples-serve-jit-smoke.sh', $common);
    }

    public function testPhpcServeSupportsJitFlag(): void
    {
        $phpc = (string) file_get_contents(self::$root.'/bin/phpc.php');
        $this->assertStringContainsString("'--jit'", $phpc);
        $this->assertStringContainsString('serve-jit.php', $phpc);
    }

    public function testServeJitBinaryExists(): void
    {
        $this->assertFileExists(self::$root.'/bin/serve-jit.php');
    }

    public function testLocalCiMatrixDocumentsServeJitSmokeGate(): void
    {
        $matrix = (string) file_get_contents(self::$root.'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SERVE_JIT_SMOKE_GATE', $matrix);
        $this->assertStringContainsString('examples-serve-jit-smoke', $matrix);
        $this->assertStringContainsString('#2274', $matrix);
    }
}
