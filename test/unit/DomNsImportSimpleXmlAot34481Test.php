<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Dom\import_simplexml user-script lowering (#34481).
 *
 * @group llvm
 * @group aot
 */
final class DomNsImportSimpleXmlAot34481Test extends TestCase
{
    private const EXPECTED = "Dom\\Element\nroot:1\nDONE";

    public function testAotNsImportSimpleXmlMatchesExpected(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';

        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34481_dom_ns_import_simplexml_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_ns_import_sxe_34481_'.getmypid();
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
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
