<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded urlencode/str_rot13/quotemeta on typed strings must not lower (#36386).
 *
 * php-src:
 * - ext/standard/url.c PHP_FUNCTION(urlencode) / rawurlencode / urldecode / rawurldecode
 * - ext/standard/string.c PHP_FUNCTION(str_rot13) / quotemeta — Z_PARAM_STR
 *
 * @group aot-lint
 */
final class DiscardedUrlencodeRot13ElisionAotTest extends TestCase
{
    public function testDiscardedUrlencodeRot13AbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                urlencode($s);
                rawurlencode($s);
                urldecode($s);
                rawurldecode($s);
                str_rot13($s);
                quotemeta($s);
                $c += $i;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c + strlen($s) + strlen(urlencode($s));
        }
        echo work('a b.c', 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_url_rot_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_url_rot_'.getmypid().'.bin';
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

            // Discarded transforms must not allocate / call per iteration.
            // Live return urlencode($s) may remain once outside the loop.
            $this->assertSame(
                1,
                preg_match_all('/call [^\n]*@__string__urlencode\b/', $body),
                'exactly one live urlencode; discarded loop calls must be elided'
            );
            $this->assertSame(0, preg_match_all('/call [^\n]*@__string__rawurlencode\b/', $body));
            $this->assertSame(0, preg_match_all('/call [^\n]*@__string__urldecode\b/', $body));
            $this->assertSame(0, preg_match_all('/call [^\n]*@__string__rawurldecode\b/', $body));
            $this->assertSame(0, preg_match_all('/call [^\n]*@__compiler_str_rot13\b/', $body));
            $this->assertSame(0, preg_match_all('/call [^\n]*@__string__quotemeta\b/', $body));

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + strlen('a b.c')=5 + strlen(urlencode('a b.c'))=strlen('a+b.c')=5 → 20
            $this->assertSame('20', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
