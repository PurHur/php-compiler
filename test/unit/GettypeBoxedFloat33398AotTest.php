<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: gettype() on boxed float must not SIGSEGV (#33398).
 *
 * @see php-src ext/standard/basic_functions.c PHP_FUNCTION(gettype)
 * @see ext/standard/JitGettype.php — BB-guarded long/HT probes (peer #26885)
 *
 * @group llvm
 * @group aot
 */
final class GettypeBoxedFloat33398AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/gettype_boxed_float_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertSame("double\ndouble\ndouble\n", $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testHelperBbGuardsLongAndHtProbes(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/gettype_boxed_float.c');
        $helper = (string) file_get_contents($root.'/ext/standard/JitGettype.php');
        $this->assertStringContainsString('gettype_long_probe', $helper);
        $this->assertStringContainsString('gettype_ht_probe', $helper);
        $this->assertStringContainsString('#33398', $helper);
        $this->assertStringContainsString('0x7f', $helper);
    }
}
