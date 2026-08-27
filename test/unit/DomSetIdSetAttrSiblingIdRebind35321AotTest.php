<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttribute('id') after setIdAttribute must clear old id when a sibling has id= (#35321).
 *
 * php-src: ext/dom/element.c — dom_element_set_attribute / xmlSetProp id table
 *
 * @group llvm
 * @group aot
 */
final class DomSetIdSetAttrSiblingIdRebind35321AotTest extends TestCase
{
    public function testSiblingIdRebindMatchesZend(): void
    {
        $src = __DIR__.'/../repro/dom_setid_setattr_sibling_id_rebind_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('x1=null', $aot);
        $this->assertStringContainsString('x2=c', $aot);
        $this->assertStringContainsString('z2=a', $aot);
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
        $bin = sys_get_temp_dir().'/dom_setid_setattr_35321_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
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
