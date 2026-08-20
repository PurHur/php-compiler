<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes after ParentNode::replaceChildren (#32846).
 *
 * php-src: ext/dom/parentnode.c / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomParentNodeReplaceChildrenLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "before_held_len=2\n"
        ."after_held_len=2\n"
        ."held0=c\n"
        ."held1=d\n"
        ."refetch_len=2\n"
        ."refetch0=c\n"
        ."refetch1=d\n"
        ."empty_held_len=0\n"
        ."empty_refetch_len=0\n";

    public function testVmParentNodeReplaceChildrenLiveHeld(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_32846_dom_replacechildren_live_held.php'
            );
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'issue_32846_dom_replacechildren_live_held.php'));
            $out = (string) ob_get_clean();
            $this->assertSame(self::EXPECTED, $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAotParentNodeReplaceChildrenLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32846_dom_replacechildren_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_rc_held_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_PROFILE=8.4 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            if (is_file($bin)) {
                @unlink($bin);
            }
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
