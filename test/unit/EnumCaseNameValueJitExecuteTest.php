<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for enum case ->name / ->value (#4953, Zend/zend_enum.c).
 *
 * @group llvm
 */
final class EnumCaseNameValueJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum case name/value JIT execute needs LLVM (#4953)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4953)');
        }
    }

    public function testEnumCaseNameValueMcjitOutput(): void
    {
        $this->assertMcjitOutput(
            $this->phptFixtureCode('enum_case_name_value.phpt'),
            "A|a\nB|bb\na\n"
        );
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_name_value.phpt');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block);
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
