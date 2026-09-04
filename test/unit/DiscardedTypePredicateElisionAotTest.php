<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded type.c predicates / typed-string strlen must not lower (#36386).
 *
 * php-src: ext/standard/type.c — is_* only read zval tags; strlen on an
 * already-string slot has no Z_PARAM_STR coercion.
 *
 * @group aot-lint
 */
final class DiscardedTypePredicateElisionAotTest extends TestCase
{
    public function testDiscardedIsPredicatesAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(mixed $x, int $n): int {
            $s = 0;
            for ($i = 0; $i < $n; ++$i) {
                is_int($x);
                is_string($x);
                is_array($x);
                is_null($x);
                is_numeric($x);
                $s += $i;
            }
            return $s;
        }
        echo work(1, 5), "\n";
        echo work('a', 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_is_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_is_'.getmypid().'.bin';
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

            $this->assertStringNotContainsString('is_int', $body);
            $this->assertStringNotContainsString('is_string', $body);
            $this->assertStringNotContainsString('is_array', $body);
            $this->assertStringNotContainsString('is_null', $body);
            $this->assertStringNotContainsString('is_numeric', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(2, $runOut);
            $this->assertSame('10', $runOut[0]);
            $this->assertSame('3', $runOut[1]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDiscardedStrlenOnStringFormalAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $n): int {
            $c = 0;
            for ($i = 0; $i < $n; ++$i) {
                strlen($s);
                $c += $i;
            }
            return $c + strlen($s);
        }
        echo work('ab', 4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_strlen_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_strlen_'.getmypid().'.bin';
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

            // Discarded strlen($s) gone. Live return strlen($s) may inline to a
            // length-field load (labels can still say strlen_*) — assert no call.
            $this->assertDoesNotMatchRegularExpression('/\bcall\b[^\n]*\bstrlen\b/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(1, $runOut);
            $this->assertSame('8', $runOut[0]); // 0+1+2+3 + strlen('ab')=2
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
