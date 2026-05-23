<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * LLVM 9 readiness for JIT/AOT tests (issue #98).
 */
final class LlvmToolchainTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testIsReadyWhenBundledLlvmPresent(): void
    {
        if (!is_file(self::$root.'/.llvm/libLLVM-9.so.1') && !is_file('/opt/llvm9/libLLVM-9.so.1')) {
            $this->markTestSkipped('LLVM 9 not installed in this environment.');
        }

        $ref = new \ReflectionClass(LlvmToolchain::class);
        $prop = $ref->getProperty('ready');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        $failProp = $ref->getProperty('readyFailure');
        $failProp->setAccessible(true);
        $failProp->setValue(null, null);

        $this->assertTrue(
            LlvmToolchain::isReady(self::$root),
            LlvmToolchain::readyFailureReason() ?? 'LlvmToolchain::isReady returned false'
        );
        $this->assertNotEmpty(LlvmToolchain::resolveDir(self::$root));
    }

    public function testApplyCurrentProcessEnvUsesAbsoluteLlvmPath(): void
    {
        if (!is_file(self::$root.'/.llvm/libLLVM-9.so.1') && !is_file('/opt/llvm9/libLLVM-9.so.1')) {
            $this->markTestSkipped('LLVM 9 not installed in this environment.');
        }

        putenv('LD_LIBRARY_PATH=./.llvm');
        $_ENV['LD_LIBRARY_PATH'] = './.llvm';
        LlvmToolchain::applyCurrentProcessEnv(self::$root);

        $ld = getenv('LD_LIBRARY_PATH');
        $this->assertIsString($ld);
        $this->assertStringNotContainsString('./.llvm', $ld);
        $this->assertStringContainsString(LlvmToolchain::resolveDir(self::$root) ?? '', $ld);
    }
}
