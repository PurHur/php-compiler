<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostLinkTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testLinkScriptSurfacesApplyPatchesFailure(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('apply-patches failed (#2806)', $script);
        $this->assertStringNotContainsString('apply-patches.sh" >/dev/null', $script);
    }

    public function testNativeLinkScriptPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for self-host native link smoke test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-link.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-link: OK', $out);
        $binary = self::$root.'/build/selfhost';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec(self::$root.'/build/selfhost');
        $this->assertIsString($runOut);
        $this->assertSame('compiler_minimal bundle OK', trim(str_replace("\n", '', $runOut)));
    }
}
