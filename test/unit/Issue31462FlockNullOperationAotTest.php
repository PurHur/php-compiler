<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: flock(null) soft DEP + ValueError; strict TypeError (#31462).
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(flock)
 *
 * @group llvm
 * @group aot
 */
final class Issue31462FlockNullOperationAotTest extends TestCase
{
    public function testAotSoftNullDepThenValueError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        // No set_error_handler — AOT rejects closures (#1379) and typed named handlers.
        // Default deprecation goes to stderr; ValueError is caught on stdout.
        $out = $this->compileAndRun($this->softProbeSource(), 'soft');
        $this->assertStringContainsString(
            'Passing null to parameter #2 ($operation) of type int is deprecated',
            $out
        );
        $this->assertStringContainsString(
            'ValueError: flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN',
            $out
        );
        $this->assertStringNotContainsString('no_throw', $out);
    }

    public function testAotStrictTypesTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $out = $this->compileAndRun($this->strictProbeSource(), 'strict');
        $this->assertSame(
            "TypeError: flock(): Argument #2 (\$operation) must be of type int, null given\n",
            $out
        );
    }

    private function softProbeSource(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($fp);
PHP;
    }

    private function strictProbeSource(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($fp);
PHP;
    }

    private function compileAndRun(string $source, string $tag): string
    {
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31462_'.$tag.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_31462_'.$tag.'_'.getmypid().'.bin';
        file_put_contents($src, $source);
        // Match Issue31408 / Issue29976 — default helper-runtime link (HELPER_RUNTIME_O=0
        // fails unresolved __phpc_url_rewriter_apply on this host).
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, 'run: '.implode("\n", $runOut));

            return implode("\n", $runOut).([] === $runOut ? '' : "\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
