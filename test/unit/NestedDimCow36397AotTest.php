<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT: nested `$a['x']['y'] =` after `$a = $b` must SEPARATE the child HT (#36397).
 *
 * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W → SEPARATE_ARRAY on nested zval.
 */
final class NestedDimCow36397AotTest extends TestCase
{
    public function testNestedStringKeyDimWriteDoesNotAliasSource(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/nested_dim_cow_36397.php';
        $bin = sys_get_temp_dir().'/phpc_nested_dim_cow_36397_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, "run failed:\n".implode("\n", $runOut));
        $this->assertSame("1|9\n", implode("\n", $runOut)."\n");
    }

    public function testWalkRecursiveDoesNotAliasNestedSource(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/walk_recursive_cow.php';
        if (!is_file($src)) {
            $src = $root.'/test/differential/cases/array_cow_walk_recursive_36397.php';
        }
        $bin = sys_get_temp_dir().'/phpc_walk_rec_cow_36397_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, "compile failed:\n".implode("\n", $out));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, "run failed:\n".implode("\n", $runOut));
        $this->assertSame("1|2\n", implode("\n", $runOut)."\n");
    }
}
