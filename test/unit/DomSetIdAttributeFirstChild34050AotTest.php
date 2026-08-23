<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setIdAttribute on firstChild registers that element's id (#34050).
 *
 * @group llvm
 * @group aot
 */
final class DomSetIdAttributeFirstChild34050AotTest extends TestCase
{
    private const EXPECT = "x=a\ny=null\nattr=x\nfoo=fx\nm1=a\na";

    public function testAotFirstChildSetIdAttributeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_34050_dom_setidattribute_firstchild_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame(self::EXPECT, trim($zend));
        $this->assertSame($zend, $aot);
    }

    public function testLaterSiblingFixtureStillGreen(): void
    {
        $src = __DIR__.'/../repro/dom_setidattribute_getelementbyid_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('y=b', $aot);
        $this->assertStringContainsString('x=null', $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_setid_gei_34050_'.getmypid();
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
