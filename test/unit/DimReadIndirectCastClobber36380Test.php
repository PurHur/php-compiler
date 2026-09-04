<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #36380 — FETCH_DIM (read) leaves TYPE_INDIRECT; short-circuit (bool) cast / ASSIGN into a
 * reused temp must not write through into the HT (Parsedown `$Block['data']['type']`).
 *
 * php-src: Zend/zend_execute.c zend_fetch_dimension_address (read copies); IS_TMP_VAR casts.
 *
 * @group unit
 */
final class DimReadIndirectCastClobber36380Test extends TestCase
{
    public function testOrAndPregMatchDoesNotClobberNestedTypeVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/list_type_cmp_preg_36380.php';
        $this->assertFileExists($src);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "VM rc=$rc out=".implode("\n", $out));
        $text = implode("\n", $out);
        $this->assertStringContainsString("before='ul'", $text);
        $this->assertStringContainsString("after='ul'", $text);
        $this->assertStringContainsString('cond=true', $text);
    }

    public function testParsedownSparseDenseListVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/parsedown-36380/sparse_dense_list.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "VM rc=$rc out=".implode("\n", $out));
        $this->assertStringContainsString('MATCH=yes', implode("\n", $out));
    }

    public function testExplicitRefAssignAndCastStillWriteThroughVm(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
<?php
$a = ['k' => 'old'];
$r =& $a['k'];
$r = 'new';
echo 'assign=', $a['k'], "\n";
$r = (bool) 1;
echo 'cast=', var_export($a['k'], true), "\n";
PHP;
        $path = sys_get_temp_dir().'/phpc_ref_cast_36380_'.getmypid().'.php';
        file_put_contents($path, $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
                .' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, "VM rc=$rc out=".implode("\n", $out));
            $this->assertSame("assign=new\ncast=true", rtrim(implode("\n", $out)));
        } finally {
            @unlink($path);
        }
    }
}
