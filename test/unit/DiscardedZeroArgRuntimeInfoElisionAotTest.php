<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded get_declared_* / get_included_files / php_sapi_name / zend_version
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/basic_functions.c, ext/standard/info.c, Zend/zend.c
 *
 * @group aot-lint
 */
final class DiscardedZeroArgRuntimeInfoElisionAotTest extends TestCase
{
    public function testDiscardedOnlyZeroArgRuntimeInfoHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                get_declared_classes();
                get_declared_interfaces();
                get_declared_traits();
                get_included_files();
                get_required_files();
                php_sapi_name();
                zend_version();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_zari_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_zari_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_php_sapi_name/', $body),
                'discarded php_sapi_name must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_zend_version/', $body),
                'discarded zend_version must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeHashtable/', $body),
                'discarded get_declared_* / get_included_files must not materialize tables'
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

    public function testLiveZeroArgRuntimeInfoMatchZend(): void
    {
        // Avoid php_sapi_name+zend_version together — pre-existing StringInfo
        // bridge failure (__compiler_phpversion missing, #13803).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        get_declared_classes();
        get_included_files();
        php_sapi_name();
        $classes = get_declared_classes();
        $inc = get_included_files();
        $sapi = php_sapi_name();
        echo (is_array($classes) && count($classes) > 0 ? '1' : '0')
            . (is_array($inc) ? '1' : '0')
            . (is_string($sapi) && '' !== $sapi ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_zari_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_zari_live_'.getmypid().'.bin';
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
