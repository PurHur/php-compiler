<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 *
 * Native `__string__*[]` (phpdoc list<string>) as a string-key array element (#36387).
 */
final class NativeStringArrayStringKeyStore36387Test extends TestCase
{
    public function testAotNestedListUnderStringKeyMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/native_string_array_string_key_store_36387.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc-nsa-sk-'.bin2hex(random_bytes(4)).'.bin';
        $cache = sys_get_temp_dir().'/phpc-nsa-sk-cache-'.bin2hex(random_bytes(4));
        mkdir($cache, 0755, true);

        $cmd = 'PHP_COMPILER_HELPER_RUNTIME_O=1'
            .' PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache)
            .' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src)
            .' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);

        exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
        $this->assertSame(0, $aotRc, implode("\n", $aotOut));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame(implode("\n", $zendOut), implode("\n", $aotOut));

        @unlink($bin);
    }
}
