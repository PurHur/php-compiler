<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: setcookie/setrawcookie excess argc → Zend ArgumentCountError wording (#30713).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(setcookie) / setrawcookie
 *
 * @group llvm
 * @group aot
 */
final class Issue30713SetcookieExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcMessagesMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30713_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30713_ex_'.getmypid().'.bin';
        file_put_contents($src, file_get_contents($root.'/test/repro/issue_30713_setcookie_excess_argc_aot.php'));
        // Default helper-runtime link — HELPER_RUNTIME_O=0 omits url_rewriter needed by setcookie/ob path.
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                "pos:ArgumentCountError:setcookie() expects at most 7 arguments, 8 given\n"
                ."raw:ArgumentCountError:setrawcookie() expects at most 7 arguments, 8 given\n"
                ."opts:ArgumentCountError:setcookie(): Expects exactly 3 arguments when argument #3 (\$expires_or_options) is an array\n",
                implode("\n", $runOut)."\n"
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
