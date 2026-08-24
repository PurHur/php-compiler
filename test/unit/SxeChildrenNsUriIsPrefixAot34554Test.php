<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: SimpleXMLElement::children($uri) uses isPrefix=false (#34554).
 */
final class SxeChildrenNsUriIsPrefixAot34554Test extends TestCase
{
    public function testAotMatchesZendOnNsUriChildren(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34554_sxe_children_ns_uri_aot.php';
        $this->assertFileExists($src);

        $zend = [];
        $zendRc = 0;
        exec('php '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, 'Zend repro failed: '.implode("\n", $zend));
        $zendOut = implode("\n", $zend);

        $bin = sys_get_temp_dir().'/phpc_sxe_34554_'.getmypid();
        $compile = [];
        $compileRc = 0;
        exec(
            'php '.escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1',
            $compile,
            $compileRc
        );
        $this->assertSame(0, $compileRc, 'AOT compile failed: '.implode("\n", $compile));
        $this->assertFileExists($bin);

        $aot = [];
        $aotRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $aot, $aotRc);
        @unlink($bin);
        $this->assertSame(0, $aotRc, 'AOT run failed: '.implode("\n", $aot));
        $this->assertSame($zendOut, implode("\n", $aot));
    }
}
