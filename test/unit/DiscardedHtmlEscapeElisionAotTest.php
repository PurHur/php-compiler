<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * htmlspecialchars / nl2br / preg_quote / escapeshellarg AOT: discarded typed
 * calls may elide; used results must match Zend (#36386).
 * php-src: ext/standard/html.c, string.c, exec.c; ext/pcre/php_pcre.c
 *
 * @group aot-lint
 */
final class DiscardedHtmlEscapeElisionAotTest extends TestCase
{
    public function testHtmlEscapeUsedResultsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s): string {
            return htmlspecialchars($s, ENT_QUOTES)
                . '|' . nl2br($s, false)
                . '|' . preg_quote($s, '/')
                . '|' . htmlentities($s)
                . '|' . htmlspecialchars_decode(htmlspecialchars($s, ENT_QUOTES), ENT_QUOTES);
        }
        echo work("a&b"), "\n";
        echo work('<x>'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_html_esc_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_html_esc_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend for html/escape builtins');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDiscardedHtmlEscapeOnTypedStringMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                htmlspecialchars($s, ENT_QUOTES);
                htmlentities($s);
                nl2br($s);
                preg_quote($s, '/');
                htmlspecialchars_decode($s, ENT_QUOTES);
                $c += $k;
            }
            return $c + strlen(htmlspecialchars($s, ENT_QUOTES));
        }
        echo work('a&b', 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_html_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_html_'.getmypid().'.bin';
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
            $this->assertCount(1, $runOut);
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
