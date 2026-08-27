<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttribute('id') after setIdAttribute clears old getElementById (#35321).
 *
 * php-src: ext/dom/element.c — setAttribute / xmlAddID / xmlRemoveID
 *
 * @group llvm
 * @group aot
 */
final class DomSetAttributeIdRebind35321AotTest extends TestCase
{
    public function testSetAttributeIdRebindMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_35321_setattribute_id_rebind_stale.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame("null\nc\n", $aot);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_setattr_id_35321_'.getmypid();
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        try {
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
