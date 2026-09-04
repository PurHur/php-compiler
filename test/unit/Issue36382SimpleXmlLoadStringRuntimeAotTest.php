<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: simplexml_load_string non-literal soft-false + literal fold (#36382 / #26863).
 *
 * php-src: ext/simplexml/simplexml.c — PHP_FUNCTION(simplexml_load_string)
 *
 * @group llvm
 * @group aot
 */
final class Issue36382SimpleXmlLoadStringRuntimeAotTest extends TestCase
{
    // param path → soft-false; top-level literal → folded SXE (ok)
    private const EXPECTED = "fail\nok";

    public function testAotNonLiteralSoftFalseAndLiteralFold(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_simplexml_runtime_load.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_sxe_load_string_rt_36382_'.getmypid();
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
