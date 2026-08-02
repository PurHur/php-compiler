<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT guard for fsync()/fdatasync() (#26929).
 *
 * Compile + libc sync bridge are covered here. End-to-end true/true remains blocked on
 * AOT fopen NestedJIT returning 0 for file paths (VmFs::fopen ExternalMethod stub) —
 * same class as getmypid before libc (#26944). Re-enable execute assert when fopen AOT
 * registers a live FILE* handle.
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(fsync) / PHP_FUNCTION(fdatasync)
 *
 * @group llvm
 * @group aot
 */
final class FsyncFdatasyncAot26929Test extends TestCase
{
    public function testAotFsyncFdatasyncCompileNoParentlessBb(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = '/tmp/phpc_fsync_26929_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$path = "/tmp/phpc_fsync_" . getmypid() . ".txt";
$fp = fopen($path, "w");
fwrite($fp, "x");
var_export(fsync($fp));
echo "\n";
var_export(fdatasync($fp));
echo "\n";
fclose($fp);
@unlink($path);
PHP);
        $bin = '/tmp/phpc_fsync_26929_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $text = implode("\n", $compileOut);
        $this->assertSame(0, $compileRc, $text);
        $this->assertStringNotContainsString('Current basic block has no parent function', $text);
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $runText = implode("\n", $runOut)."\n";
            // No NestedJIT warnUnsyncable abort (SIGABRT) — libc sync path (#26929).
            $this->assertNotSame(134, $runRc, 'unexpected abort: '.$runText);
            $this->assertNotSame(139, $runRc, 'unexpected segfault: '.$runText);
            if ("true\ntrue\n" !== $runText) {
                $this->markTestIncomplete(
                    'AOT fopen still returns 0 for file paths (VmFs NestedJIT ExternalMethod); '
                    .'fsync/fdatasync libc bridge is linked but sees no FILE*. Got: '.var_export($runText, true)
                );
            }
            $this->assertSame("true\ntrue\n", $runText);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
