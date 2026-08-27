<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: setIdAttribute on nested siblings + replaceChild with held childNodes (#21644 follow-up).
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceChildSetIdAttributeIdMapAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
id1=Y id2=Y
len=2 item0=x
OK
TXT;

    public function testAotReplaceChildSetIdAttributeIdMap(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_dom_replacechild_setidattribute_idmap.php';
        $bin = sys_get_temp_dir().'/phpc_dom_rc_sid_'.getmypid().'.bin';
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
