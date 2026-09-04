<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded phpversion / php_uname / getmypid / getmyuid / getmygid
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/info.c, ext/standard/basic_functions.c
 *
 * @group aot-lint
 */
final class DiscardedProcessIdentityElisionAotTest extends TestCase
{
    public function testDiscardedOnlyProcessIdentityHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, string $mode, string $ext): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                phpversion();
                phpversion($ext);
                php_uname();
                php_uname($mode);
                getmypid();
                getmyuid();
                getmygid();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 's', 'standard'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_pi_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_pi_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_phpversion/', $body),
                'discarded phpversion must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_php_uname/', $body),
                'discarded php_uname must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_getmypid/', $body),
                'discarded getmypid must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/resolveGetmyuid/', $body),
                'discarded getmyuid must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/resolveGetmygid/', $body),
                'discarded getmygid must not call helper'
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

    public function testLiveProcessIdentityMatchZend(): void
    {
        // Avoid asserting absolute pid/uid/version strings — only shapes that
        // both Zend and AOT helpers agree on (peer StringInfo gaps).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        phpversion();
        php_uname('s');
        getmypid();
        $ver = phpversion();
        $uname = php_uname('s');
        $pid = getmypid();
        echo (is_string($ver) && '' !== $ver ? '1' : '0')
            . (is_string($uname) && '' !== $uname ? '1' : '0')
            . (is_int($pid) && $pid > 0 ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pi_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pi_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $runOut = [];
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
