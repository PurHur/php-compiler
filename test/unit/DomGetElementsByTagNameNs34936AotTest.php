<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName(NS) on prefixed loadXML children (#34936).
 *
 * @see php-src ext/dom/php_dom.c get_elements_by_tag_name / get_elements_by_tag_name_ns
 * @see php-src ext/dom/nodelist.c php_dom_nodelist_item
 *
 * @group llvm
 * @group aot
 */
final class DomGetElementsByTagNameNs34936AotTest extends TestCase
{
    private const EXPECTED = <<<'EOF'
array (
  0 => 'x',
  1 => 'a',
  2 => 'urn:x',
  3 => 'hi',
)
len=1
array (
  0 => 'x',
  1 => 'a',
  2 => 'urn:x',
  3 => 'hi',
)

EOF;

    public function testAotGetElementsByTagNameNsPrefixed(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34936_getelementsbytagname_ns_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34936_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
