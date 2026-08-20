<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes live pins after ChildNode::replaceWith / remove (#32821).
 *
 * php-src: ext/dom/php_dom.c / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeReplaceRemoveLiveHeldAotTest extends TestCase
{
    private const REPLACE_EXPECTED =
        "held_len=3\nheld0=z\nheld1=b\nheld2=c\nrefetch_len=3\nrefetch0=z\n";

    private const REMOVE_EXPECTED =
        "held_len=2\nheld0=b\nheld1=c\nrefetch_len=2\nrefetch0=b\n";

    public function testVmChildNodeReplaceWithLiveHeld(): void
    {
        $this->assertVmRepro(
            'issue_32821_dom_childnode_replacewith_live_held.php',
            self::REPLACE_EXPECTED
        );
    }

    public function testVmChildNodeRemoveLiveHeld(): void
    {
        $this->assertVmRepro(
            'issue_32821_dom_childnode_remove_live_held.php',
            self::REMOVE_EXPECTED
        );
    }

    public function testAotChildNodeReplaceWithLiveHeld(): void
    {
        $this->assertAotRepro(
            'issue_32821_dom_childnode_replacewith_live_held.php',
            self::REPLACE_EXPECTED,
            'phpc_dom_replacewith_held_'
        );
    }

    public function testAotChildNodeRemoveLiveHeld(): void
    {
        $this->assertAotRepro(
            'issue_32821_dom_childnode_remove_live_held.php',
            self::REMOVE_EXPECTED,
            'phpc_dom_remove_held_'
        );
    }

    private function assertVmRepro(string $file, string $expected): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/'.$file);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, $file));
        $out = (string) ob_get_clean();
        $this->assertSame($expected, $out);
    }

    private function assertAotRepro(string $file, string $expected, string $binPrefix): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$file;
        $bin = sys_get_temp_dir().'/'.$binPrefix.getmypid().'.bin';
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
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
