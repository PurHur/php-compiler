<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for implicit nullable typed parameters (#4767, #4449).
 *
 * php-src: Zend/zend_compile.c (nullable default on non-?T types), zend_execute.c
 *
 * @group llvm
 */
final class ImplicitNullableParamJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — implicit nullable JIT execute needs LLVM (#4767)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4767)');
        }
    }

    public function testImplicitNullableParamPhptExecutesViaMcjit(): void
    {
        $this->assertMcjitOutput(
            $this->phptFixtureCode('implicit_nullable_param.phpt'),
            "null\n"
        );
    }

    public function testImplicitNullableParamAcceptsExplicitNull(): void
    {
        $this->assertMcjitOutput(
            <<<'PHP'
<?php
function f(int $x = null) {
    echo null === $x ? "null\n" : "set\n";
}
f(null);
PHP,
            "null\n"
        );
    }

    public function testImplicitNullableParamAcceptsIntValue(): void
    {
        $this->assertMcjitOutput(
            <<<'PHP'
<?php
function f(int $x = null) {
    echo null === $x ? "null\n" : "set\n";
}
f(42);
PHP,
            "set\n"
        );
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block, $code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }

    private function jitRuntimeProbeGreen(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe))
        );
        exec($cmd, $out, $code);

        return 0 === $code && str_contains(implode("\n", $out), 'jit-runtime-probe OK');
    }
}
