<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMXPath attribute-axis evaluate/query follow-ups (#32003).
 *
 * Host-fold count(//@*), string(//@*), and relative query('@*', $ctx) so user-script
 * AOT avoids the context-less XPath evaluate ABI (SIGSEGV / empty node-set).
 *
 * @group llvm
 */
final class DomXpathAttrStarEvaluateAotTest extends TestCase
{
    public function testAttrStarLoopMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_xpath_attr_star_loop.php');
    }

    public function testCountAttrStarMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_xpath_count_attr_star.php');
    }

    public function testStringAttrStarMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_xpath_string_attr_star.php');
    }

    public function testRelativeAttrStarWithContextMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_xpath_rel_attr_star.php');
    }

    /** Multiple host-folded axis queries in one script — axis id stamped per NodeList (#32003). */
    public function testMultiQueryAttrStarNamesFnMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/maintainer_gap_dom_xpath_attr_star.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
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
        $bin = sys_get_temp_dir().'/dom_xpath_attr_eval_'.getmypid().'_'.md5($src);
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
