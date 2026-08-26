<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35067 / #35050 — AOT htmlentities() UTF-8 entity map under NestedJIT.
 *
 * Probe uses string locals + encoding arg so VmString compile-time fold cannot
 * hide a broken helper-runtime unit (leftover #35050 cheap green).
 *
 * @see php-src ext/standard/html.c php_html_entities
 *
 * @group llvm
 * @group aot
 */
final class HtmlEntitiesUtf8NestedJitIsset35050Test extends TestCase
{
    public function testAotHtmlentitiesUtf8MatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_htmlentities_utf8_isset_probe.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertStringContainsString('caf&eacute;', $zend);
        $this->assertStringContainsString('&eacute;', $zend);

        $bin = sys_get_temp_dir().'/phpc_35067_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend htmlentities UTF-8 locals');
        $this->assertSame(0, $aot['code']);
    }

    public function testHelperUtf8CharWidthUsesStrlenNotIsset(): void
    {
        $path = dirname(__DIR__, 2).'/ext/standard/HtmlEntitiesJitHelper.php';
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('\\strlen($string)', $src);
        $this->assertStringNotContainsString('isset($string[$i + 1])', $src);
        $this->assertStringNotContainsString('isset($string[$i + 2])', $src);
        $this->assertStringContainsString('entityForCodePoint', $src);
        $this->assertStringContainsString('byteOrd', $src);
        $this->assertStringNotContainsString('\\ord($', $src);
        $this->assertStringNotContainsString('isset($map[$', $src);
    }

    public function testHelperEncodeMatchesZendCafe(): void
    {
        $out = \PHPCompiler\ext\standard\HtmlEntitiesJitHelper::encode('café', \ENT_QUOTES);
        $this->assertSame('caf&eacute;', $out);
    }

    private function runPhp(string $src): string
    {
        $cmd = 'php '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "Zend failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "VM failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    /** @return array{code:int,out:string} */
    private function runCompile(string $src, string $bin): array
    {
        $root = dirname(__DIR__, 2);
        // Default helper-runtime (prelinked) — must not rely on O=0 to hide a stale unit (#35067).
        $cmd = 'php '.escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines)];
    }

    /** @return array{code:int,out:string} */
    private function runBin(string $bin): array
    {
        exec(escapeshellarg($bin).' 2>&1', $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines)."\n"];
    }
}
