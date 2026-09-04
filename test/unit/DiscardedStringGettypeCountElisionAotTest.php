<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded gettype / string transforms / count on typed arrays must not lower (#36386).
 *
 * php-src:
 * - ext/standard/basic_functions.c PHP_FUNCTION(gettype) — type-tag only
 * - ext/standard/string.c strtolower/trim/… — Z_PARAM_STR; typed-string has no coerce
 * - Zend/zend_builtin_functions.c PHP_FUNCTION(count) — HashTable path only (no Countable)
 *
 * @group aot-lint
 */
final class DiscardedStringGettypeCountElisionAotTest extends TestCase
{
    public function testDiscardedGettypeStringCountAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, array $a, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                gettype($s);
                strtolower($s);
                strtoupper($s);
                trim($s);
                strrev($s);
                count($a);
                sizeof($a);
                $c += $i;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c + strlen($s) + count($a);
        }
        echo work(' Ab ', [1, 2, 3], 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_str_gt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_str_gt_'.getmypid().'.bin';
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

            // Discarded transforms must not allocate per iteration.
            $allocCount = substr_count($body, 'call %__string__* @__string__alloc');
            $this->assertSame(
                0,
                $allocCount,
                'discarded strtolower/trim/strrev/gettype must not allocate string results'
            );
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_strtolower\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_strtoupper\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_trim\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_strrev\b/', $body);
            // Live return count($a) may remain; discarded loop counts must not multiply.
            // Presence of gettype label loads for discarded calls would show constant strings
            // like "string" stored repeatedly — reject gettype helper symbols if any.
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_gettype\b/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + strlen(' Ab ')=4 + count([1,2,3])=3 → 17
            $this->assertSame('17', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
