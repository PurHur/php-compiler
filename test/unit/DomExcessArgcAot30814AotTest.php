<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: DOM ChildNode::remove() + DOM residual excess argc → ArgumentCountError (#30814 / #31251 / #31090).
 *
 * php-src: ext/dom/php_dom.stub.php / characterdata.c
 *
 * @group llvm
 * @group aot
 */
final class DomExcessArgcAot30814AotTest extends TestCase
{
    /**
     * @dataProvider excessArgcReproProvider
     */
    public function testExcessArgcReproMatchesZend(string $reproBasename): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproBasename;
        $bin = sys_get_temp_dir().'/phpc_dom_excess_'.md5($reproBasename).'_'.getmypid().'.bin';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zRc);
        $this->assertSame(0, $zRc, implode("\n", $zend));
        $expected = implode("\n", $zend)."\n";
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function excessArgcReproProvider(): iterable
    {
        yield 'childnode_remove_30814' => ['maintainer_gap_dom_childnode_remove_excess_argc_30814.php'];
        yield 'implementation_31090' => ['maintainer_gap_dom_implementation_excess_argc.php'];
        yield 'residual_31251' => ['maintainer_gap_dom_residual_argc_31251.php'];
    }
}
