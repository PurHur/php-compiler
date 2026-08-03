<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Committed gen-0 argv driver must be exercised on a never-seen script (#23468).
 *
 * Stamp/manifest sync alone stayed green while bin-compile-aot failed parseAndCompile
 * on every input. This unit guards the smoke script wiring — the live driver check runs
 * via `make bootstrap-gen0-driver-functional-smoke`.
 *
 * @group bootstrap
 */
final class BootstrapGen0DriverFunctionalSmokeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testFunctionalSmokeScriptExistsAndIsExecutable(): void
    {
        $script = $this->root.'/script/bootstrap-gen0-driver-functional-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), $script.' must be executable');
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('PHP_COMPILER_REPO_ROOT="${ROOT}"', $body);
        $this->assertStringContainsString('examples/000-HelloWorld/example.php', $body);
        $this->assertStringContainsString('#27426', $body);
    }

    public function testArgvDriverRefreshDoesNotClassifyBinCompileAsUserScriptAot(): void
    {
        // Guard: Zend rebuild of bin/compile.php must keep SELFHOST_AOT for real parse spine (#26756).
        // Do not require bin/compile.php here — it dispatches the CLI entrypoint.
        $compile = (string) file_get_contents($this->root.'/bin/compile.php');
        $fnPos = strpos($compile, 'function phpc_compile_is_user_script_aot');
        $this->assertNotFalse($fnPos);
        $fnChunk = substr($compile, $fnPos, 1200);
        $this->assertStringContainsString("str_ends_with(\$normalized, '/bin/compile.php')", $fnChunk);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $fnChunk);
        $this->assertStringContainsString('#26756', $fnChunk);
        // run() must not auto-putenv M5_DRIVER_HOST — src/cli.php defines the skip-entry
        // helper under Zend too, which poisoned every user-script AOT build (#27039).
        $this->assertStringContainsString('#27039', $compile);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_M5_DRIVER_HOST=1')", $compile);
    }

    public function testFunctionalSmokeFailsWhenDriverReturnsParseAndCompileNull(): void
    {
        $tmpdir = sys_get_temp_dir().'/phpc-gen0-func-'.bin2hex(random_bytes(4));
        mkdir($tmpdir, 0775, true);
        $fakeDriver = $tmpdir.'/fake-driver';
        file_put_contents($fakeDriver, <<<'SH'
#!/usr/bin/env bash
echo "helloworld_compile_smoke: parseAndCompile returned null (parser/CFG spine)" >&2
echo "helloworld_compile_smoke: native emit failed at phase=parseAndCompile" >&2
exit 1
SH);
        chmod($fakeDriver, 0755);

        $cmd = 'BOOTSTRAP_GEN0_DRIVER='.escapeshellarg($fakeDriver)
            .' BOOTSTRAP_GEN0_FUNCTIONAL_WORKDIR='.escapeshellarg($tmpdir.'/work')
            .' '.escapeshellarg($this->root.'/script/bootstrap-gen0-driver-functional-smoke.sh');
        $out = [];
        $code = 0;
        exec($cmd.' 2>&1', $out, $code);
        $this->removeTree($tmpdir);

        $this->assertSame(1, $code, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString('parseAndCompile', $joined);
        $this->assertStringContainsString('#23468', $joined);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
