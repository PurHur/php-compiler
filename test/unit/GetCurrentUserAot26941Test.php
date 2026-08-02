<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for get_current_user() (#26941).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_current_user)
 *
 * @group llvm
 * @group aot
 */
final class GetCurrentUserAot26941Test extends TestCase
{
    public function testAotGetCurrentUserNonEmptyUsername(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_get_current_user_26941_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$u = get_current_user();
echo is_string($u) && $u !== '' ? "ok\n" : "bad\n";
echo $u, "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_get_current_user_26941_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $text = implode("\n", $runOut)."\n";
                $this->assertMatchesRegularExpression('/^ok\n.+\n$/', $text, 'run '.($i + 1).': '.$text);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
