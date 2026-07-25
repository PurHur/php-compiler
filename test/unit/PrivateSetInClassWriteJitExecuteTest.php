<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * JIT driver execute for #23110 private(set) in-class writes (bin/jit.php).
 *
 * @group llvm
 */
final class PrivateSetInClassWriteJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        \PHPCompiler\LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!\PHPCompiler\LlvmToolchain::isReady($this->repoRoot)) {
            $reason = \PHPCompiler\LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #23110 JIT execute needs LLVM');
        }
    }

    public function testInClassWriteViaBinJit(): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_23110_private_set_in_class_write.php';
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg(
                'source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && export PHP_COMPILER_PROFILE=8.4'
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->repoRoot.'/bin/jit.php')
                .' '.escapeshellarg($repro)
            )
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame(
            [
                '1',
                'x:Cannot modify private(set) property T::$x from global scope',
                'y:Cannot modify protected(set) property T::$y from global scope',
            ],
            $out
        );
    }
}
