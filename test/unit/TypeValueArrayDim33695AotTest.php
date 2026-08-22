<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: TYPE_VALUE array dim must not abort via ArrayAccess (#33695).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_R
 * @see php-src Zend/zend_hash.c zend_array_dup
 *
 * @group llvm
 * @group aot
 */
final class TypeValueArrayDim33695AotTest extends TestCase
{
    public function testAotMatchesZend(): void
    {
        $src = __DIR__.'/../repro/type_value_array_dim_aot_33695.php';
        $this->assertSame($this->runPhp($src), $this->runAot($src));
    }

    public function testCanUseArrayAccessRejectsUntaggedValue(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmArrayAccess.php');
        $this->assertStringContainsString('#33695', $src);
        $this->assertStringContainsString('TYPE_VALUE === $container->type', $src);
        $this->assertStringContainsString('dimFetchValueBoxRead', $src);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/type_value_dim_33695_'.getmypid().'.bin';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
