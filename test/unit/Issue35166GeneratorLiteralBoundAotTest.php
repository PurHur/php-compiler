<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: for/while yield with literal or const bound (#35166, residual of #35142).
 *
 * php-src: Zend/zend_generators.c
 *
 * @group llvm
 * @group aot
 */
final class Issue35166GeneratorLiteralBoundAotTest extends TestCase
{
    /** @return list<string> */
    private function reproFiles(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/test/repro/issue_35166_gen_for_literal.php',
            $root.'/test/repro/issue_35166_gen_while_literal.php',
            $root.'/test/repro/issue_35166_gen_for_const.php',
        ];
    }

    public function testAotLiteralAndConstBoundYieldMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ($this->reproFiles() as $src) {
            $this->assertFileExists($src);
            $bin = sys_get_temp_dir().'/phpc_gen_35166_'.md5($src).'_'.getmypid();
            $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $cout, $crc);
            $this->assertSame(0, $crc, basename($src)." AOT compile failed:\n".implode("\n", $cout));
            $this->assertFileExists($bin);
            try {
                $aot = [];
                exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
                $zend = [];
                exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zrc);
                $this->assertSame(0, $arc, basename($src)." AOT run rc=$arc out=".implode("\n", $aot));
                $this->assertSame(0, $zrc);
                $this->assertSame(
                    implode("\n", $zend),
                    implode("\n", $aot),
                    basename($src)
                );
                $this->assertSame('012', rtrim(implode("\n", $aot)), basename($src));
            } finally {
                @unlink($bin);
            }
        }
    }
}
