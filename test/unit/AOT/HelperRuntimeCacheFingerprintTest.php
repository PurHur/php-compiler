<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #15889: helper-runtime cache invalidates on core/LLVM identity changes.
 */
final class HelperRuntimeCacheFingerprintTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testUnitFingerprintChangesWhenLlvmPathChanges(): void
    {
        $root = \dirname(__DIR__, 3);
        $tmp = \sys_get_temp_dir().'/phpc-helper-runtime-fingerprint-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php\nreturn 1;\n");
        try {
            $a = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-a');
            $b = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-b');
            $this->assertNotSame($a, $b, 'fingerprint should change when PHP_COMPILER_LLVM_PATH changes');
        } finally {
            @unlink($tmp);
        }
    }

    private function fingerprintViaSubprocess(string $root, string $unitFile, string $llvmPath): string
    {
        $php = escapeshellarg(PHP_BINARY);
        $rootArg = escapeshellarg($root);
        $unitArg = escapeshellarg($unitFile);
        $llvmLiteral = var_export($llvmPath, true);

        $code = 'chdir('.$rootArg.');'
            .'putenv("PHP_COMPILER_LLVM_PATH=" . '.$llvmLiteral.');'
            .'require "lib/AOT/HelperRuntimeCache.php";'
            .'echo \\PHPCompiler\\AOT\\HelperRuntimeCache::unitFingerprint('.$unitArg.');';
        $cmd = $php.' -r '.escapeshellarg($code);
        $out = (string) @shell_exec($cmd);

        return trim($out);
    }
}

