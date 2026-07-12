<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Bootstrap SDK platform contract guard (#15606). */
final class BootstrapSdkPlatformTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapSdkPlatformArtifactsExist(): void
    {
        $this->assertFileExists(self::$root.'/docs/bootstrap-sdk-platform.md');
        $this->assertFileExists(self::$root.'/docs/bootstrap-sdk-platform.json');
        $this->assertFileExists(self::$root.'/script/bootstrap-sdk-platform-lib.php');
        $this->assertFileExists(self::$root.'/script/check-bootstrap-sdk-platform.php');
    }

    public function testBootstrapSdkPlatformJsonMatchesContract(): void
    {
        require_once self::$root.'/script/bootstrap-sdk-platform-lib.php';

        $contract = bootstrap_sdk_platform_contract(self::$root);
        $this->assertSame(15606, $contract['issue']);
        $this->assertSame('linux', $contract['supported']['os']);
        $this->assertSame('x86_64', $contract['supported']['arch']);
        $this->assertSame(9, $contract['supported']['llvm_major']);
        $this->assertContains('macos', $contract['non_goals']);
        $this->assertContains('linux_aarch64', $contract['non_goals']);
    }

    public function testBootstrapSdkPlatformCheckPassesOnMaster(): void
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/script/check-bootstrap-sdk-platform.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
