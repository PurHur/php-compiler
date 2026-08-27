<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `$map ? $map->length : -1` must compile — boxFetchedPropertyIntoValue
 * used load(value) on int64* / ternary phi slots (#35554, peer #33018).
 *
 * @see php-src ext/dom/namednodemap.c php_dom_get_namednodemap_length
 *
 * @group llvm
 * @group aot
 */
final class DomNamedNodeMapTernaryLength35554AotTest extends TestCase
{
    public function testNamedNodeMapTernaryLengthAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/aot_dom_namednodemap_ternary_length.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    private function runVm(string $src): string
    {
        return $this->runPhp('bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_nnm_tlen_35554_'.getmypid().'_'.md5($src);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    private function runPhp(string $relBin, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$relBin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
