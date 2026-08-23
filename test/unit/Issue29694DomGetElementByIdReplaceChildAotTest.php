<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementById null after replaceChild same id + setIdAttribute (#29694 / #25274).
 *
 * php-src: ext/dom/document.c — php_dom_is_node_connected filters detached ID owners.
 *
 * @group llvm
 * @group aot
 */
final class Issue29694DomGetElementByIdReplaceChildAotTest extends TestCase
{
    public function testReplaceChildSameIdMatchesZend(): void
    {
        $src = __DIR__.'/../repro/maintainer_gap_dom_getelementbyid_replacechild_same_id.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame("null\nc", $aot);
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
        $bin = sys_get_temp_dir().'/dom_gei_replacechild_29694_'.getmypid().'_'.mt_rand();
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
