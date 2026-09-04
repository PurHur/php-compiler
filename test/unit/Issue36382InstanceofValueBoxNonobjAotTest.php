<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: instanceof on escaped non-object value boxes returns false (#36382).
 *
 * After Analyzer treats InstanceOf_ as escaping (#36669), array subjects are
 * TYPE_VALUE; emitInstanceOf must branch before class_id load.
 *
 * php-src: Zend/zend_operators.c instanceof_function / zend_is_instanceof
 *
 * @group llvm
 * @group aot
 */
final class Issue36382InstanceofValueBoxNonobjAotTest extends TestCase
{
    private const EXPECTED = "yes\nno\nno";

    public function testAotInstanceofNonObjectValueBox(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_instanceof_value_box_nonobj.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_instanceof_vb_nonobj_36382_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' --no-cache -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame(self::EXPECTED, rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
