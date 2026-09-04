<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded ord()/chr() on typed args must not lower (#36386).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(ord|chr); typed-string ord and
 * already-numeric chr have no Z_PARAM_* coercion / null deprecate side effects.
 *
 * @group aot-lint
 */
final class DiscardedChrOrdElisionAotTest extends TestCase
{
    public function testDiscardedOrdChrOnTypedArgsAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $n, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                ord($s);
                chr($n);
                $c += $i;
            }
            // Live uses stay — only discarded-only ord/chr must vanish from IR.
            return $c + ord($s) + strlen(chr($n));
        }
        echo work('A', 65, 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_chr_ord_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_chr_ord_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@work\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            // Live return still needs one chr → one __string__alloc; discarded loop
            // must not multiply allocs (before elision: loops+1 allocs).
            $allocCount = substr_count($body, 'call %__string__* @__string__alloc');
            $this->assertSame(
                1,
                $allocCount,
                'discarded chr() in loop must not allocate; only live strlen(chr()) may'
            );
            // Discarded ord() must not emit an extra empty-length icmp eq 0 + zext
            // chain per iteration — live return ord() keeps exactly one zext i8→i64.
            $zextCount = preg_match_all('/\bzext i8\b/', $body);
            $this->assertSame(
                1,
                $zextCount,
                'discarded ord() must not lower a per-iteration byte zext'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + ord('A')=65 + strlen(chr(65))=1 → 76
            $this->assertSame('76', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
