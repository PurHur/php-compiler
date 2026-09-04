<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: libxml_disable_entity_loader soft return (#36382 / Slim BodyParsingMiddleware).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_disable_entity_loader)
 *
 * @group llvm
 * @group aot
 */
final class Issue36382LibxmlDisableEntityLoaderAotTest extends TestCase
{
    private const EXPECTED = 'true';

    public function testAotDisableEntityLoaderReturnsTrue(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_libxml_disable_entity_loader.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_libxml_del_36382_'.getmypid();
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
