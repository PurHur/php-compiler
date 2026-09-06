<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach ($a as &$v) after `$a = $b` must SEPARATE so `$b` stays unchanged (#36397).
 *
 * php-src: Zend/zend_vm_def.h ZEND_FE_RESET_RW → SEPARATE_ARRAY.
 */
final class ForeachByRefCow36397AotTest extends TestCase
{
    public function testByRefForeachDoesNotAliasSource(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/foreach_byref_cow_36397.php';
        $bin = sys_get_temp_dir().'/phpc_foreach_byref_cow_36397_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, "run failed:\n".implode("\n", $runOut));
        $this->assertSame("1,2|10,20\n", implode("\n", $runOut)."\n");
    }
}
