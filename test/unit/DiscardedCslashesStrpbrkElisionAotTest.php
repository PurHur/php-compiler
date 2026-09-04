<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded addcslashes/stripcslashes/strpbrk on typed string args must not
 * lower (#36386). Live strpbrk still emits helpers and must match Zend.
 * Live addcslashes/stripcslashes AOT still segfault on master — discarded-only.
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(addcslashes|stripcslashes|strpbrk)
 *
 * @group aot-lint
 */
final class DiscardedCslashesStrpbrkElisionAotTest extends TestCase
{
    public function testDiscardedOnlyCslashesStrpbrkHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $s, string $chars, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                addcslashes($s, $chars);
                stripcslashes($s);
                strpbrk($s, $chars);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded("a\nb", 'A..z'."\n", 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cslash_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cslash_only_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $sig = null;
            if (preg_match('/define [^\n]*@only_discarded\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @only_discarded');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertSame(
                0,
                preg_match_all('/__compiler_addcslashes|__compiler_stripcslashes|phpc_strpbrk_scan|StringCslashes|StringStrpbrk|strpbrk_scan/', $body),
                'discarded addcslashes/stripcslashes/strpbrk must be elided'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testStrpbrkUsedResultsMatchZend(): void
    {
        // Live addcslashes/stripcslashes AOT still segfault on master (unchanged);
        // prove elision for those separately; live match covers strpbrk only.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $chars): string {
            addcslashes($s, $chars);
            stripcslashes($s);
            strpbrk($s, $chars);
            $found = strpbrk($s, $chars);
            return false === $found ? '0' : $found;
        }
        echo work("a\nb", 'A..z'."\n"), "\n";
        echo work('Hello', 'el'), "\n";
        echo work('xyz', 'a'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_strpbrk_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_strpbrk_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'AOT must match Zend for strpbrk');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
