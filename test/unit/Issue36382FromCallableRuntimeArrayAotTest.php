<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Closure::fromCallable([$obj, $runtimeMethod]) — Slim ServerRequestCreator (#36382 / #26788).
 *
 * php-src: Zend/zend_closures.c — zim_Closure_fromCallable
 *
 * @group llvm
 * @group aot
 */
final class Issue36382FromCallableRuntimeArrayAotTest extends TestCase
{
    private const EXPECTED = 'ok';

    public function testAotRuntimeArrayFromCallable(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_fromcallable_runtime_array.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_fromcallable_rt_arr_36382_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame(self::EXPECTED, rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
