<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-llvm Type.php must use LLVMTokenTypeKind not LLVMTokenTypeKin (#11396).
 */
final class BootstrapPhpLlvmTokenTypeKindPatchTest extends TestCase
{
    public function testTokenTypeKindPatchIsWellFormed(): void
    {
        $patch = dirname(__DIR__, 2).'/patches/php-llvm-token-type-kind-typo.patch';
        $this->assertFileExists($patch);
        $root = dirname(__DIR__, 2);
        $forward = shell_exec(
            'cd '.escapeshellarg($root)
            .' && git apply --check -p0 '.escapeshellarg($patch).' 2>&1'
        );
        $reverse = shell_exec(
            'cd '.escapeshellarg($root)
            .' && git apply --reverse --check -p0 '.escapeshellarg($patch).' 2>&1'
        );
        $this->assertTrue(
            false === strpos((string) $forward, 'corrupt patch')
            && false === strpos((string) $reverse, 'corrupt patch'),
            'php-llvm-token-type-kind-typo.patch must be a valid unified diff'
        );
        $this->assertTrue(
            false === strpos((string) $forward, 'error:')
            || false === strpos((string) $reverse, 'error:'),
            "patch must apply forward or already be applied:\nforward: {$forward}\nreverse: {$reverse}"
        );
    }

    public function testVendorAndPrelinkedTypePhpUseLLVMTokenTypeKind(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            $root.'/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php',
            $root.'/prelinked/bootstrap-vendor/sources/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php',
        ] as $path) {
            $this->assertFileExists($path, $path);
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('LLVMTokenTypeKind', $body, $path);
            $this->assertStringNotContainsString('LLVMTokenTypeKin:', $body, $path);
        }
    }

    public function testApplyPatchesRepairsPrelinkedTypo(): void
    {
        $root = dirname(__DIR__, 2);
        $target = $root.'/prelinked/bootstrap-vendor/sources/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php';
        $backup = (string) file_get_contents($target);
        try {
            file_put_contents($target, str_replace('LLVMTokenTypeKind:', 'LLVMTokenTypeKin:', $backup));
            exec('bash '.escapeshellarg($root.'/script/apply-patches.sh').' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
            $repaired = (string) file_get_contents($target);
            $this->assertStringContainsString('LLVMTokenTypeKind:', $repaired);
            $this->assertStringNotContainsString('LLVMTokenTypeKin:', $repaired);
        } finally {
            file_put_contents($target, $backup);
        }
    }
}
