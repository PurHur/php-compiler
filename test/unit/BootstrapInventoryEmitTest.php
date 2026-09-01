<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\Linker;
use PHPUnit\Framework\TestCase;

/** Issue #8709: inventory argv driver phantom emit guard — require non-empty -o always. */
final class BootstrapInventoryEmitTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testLinkerOutputGuardRejectsMissingFile(): void
    {
        $path = self::$root.'/build/aot-output-guard-missing-'.getmypid();
        @unlink($path);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('output file missing after emit');
        Linker::assertNonEmptyOutputFile($path);
    }

    public function testLinkerOutputGuardRejectsEmptyFile(): void
    {
        $path = self::$root.'/build/aot-output-guard-empty-'.getmypid();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, '');
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('output file is empty');
            Linker::assertNonEmptyOutputFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLinkerOutputGuardAcceptsNonEmptyFile(): void
    {
        $path = self::$root.'/build/aot-output-guard-ok-'.getmypid();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, 'ok');
        try {
            Linker::assertNonEmptyOutputFile($path);
            $this->addToAssertionCount(1);
        } finally {
            @unlink($path);
        }
    }

    public function testBinCompilePhpUsesLinkerOutputGuardAfterStandalone(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('Linker::assertNonEmptyRequestedOutput', $compile);
        $this->assertStringContainsString('#8709', (string) file_get_contents(self::$root.'/lib/AOT/Linker.php'));
    }

    public function testJitContextCompileToFileUsesLinkerOutputGuard(): void
    {
        $context = (string) file_get_contents(self::$root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('Linker::assertNonEmptyOutputFile', $context);
    }

    public function testBootstrapInventoryArgvEmitOutputOkDocuments8709(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('bootstrap-inventory-argv-emit: missing -o output file', $script);
        $this->assertStringContainsString('#8709', $script);
    }

    public function testInventoryArgvCompiledFirstSkipsM3SidecarSeedBeforeNativeEmit(): void
    {
        $resolve = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('bootstrap_compile_invoke_skip_m3_sidecar_seed', $resolve);
        $this->assertStringContainsString('#36144', $resolve);
        $helloworld = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-compile-bin.sh');
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $helloworld);
    }
}
