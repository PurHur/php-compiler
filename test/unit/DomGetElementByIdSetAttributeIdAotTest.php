<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementById() must rebind PROP_ELEMENT_ID_MAP after setAttribute/removeAttribute id (#19870).
 *
 * @see php-src ext/dom/element.c dom_element_set_attribute / xmlSetProp
 *
 * @group llvm
 * @group aot
 */
final class DomGetElementByIdSetAttributeIdAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
before=x
after_b=1
after_a=0
after_rm_a=0
after_rm_b=0
TXT;

    public function testAotGetElementByIdSetAttributeId(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_dom_getelementbyid_setattribute_id.php';
        $bin = sys_get_temp_dir().'/phpc_dom_gei_setattr_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
