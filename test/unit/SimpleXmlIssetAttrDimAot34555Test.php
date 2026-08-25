<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: isset/empty on SimpleXMLElement attribute dims (#34555).
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlIssetAttrDimAot34555Test extends TestCase
{
    private const EXPECTED = 'a:Ie|b:IE|missing:iE|0:Ie|';

    public function testAotIssetEmptyAttrDimMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34555_sxe_isset_attr_dim_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_sxe_isset_34555_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
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
