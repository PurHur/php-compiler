<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMXPath //*[@attr=val] wildcard predicate length (#35402).
 *
 * php-src: ext/dom/xpath.c — any-element name test with attribute predicate
 *
 * @group llvm
 * @group aot
 */
final class DomXPathWildcardAttrPredicate35349AotTest extends TestCase
{
    public function testWildcardAttrPredicateMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_35349_dom_xpath_wildcard_attr_predicate_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame("len=1\nname=a\nmiss=0\ntag=1\n", $aot);
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
        $bin = sys_get_temp_dir().'/dom_xpath_wildcard_35402_'.getmypid();
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
