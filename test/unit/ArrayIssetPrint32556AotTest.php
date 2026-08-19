<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: isset()/print on packed arrays match Zend (#32556 leftover of #32475).
 *
 * @see php-src Zend/zend_execute.c ZEND_ISSET_ISEMPTY_CV
 * @see php-src Zend/zend_vm_def.h ZEND_PRINT
 *
 * @group llvm
 * @group aot
 */
final class ArrayIssetPrint32556AotTest extends TestCase
{
    private const EXPECT = "bool(true)\nArray\n";

    public function testVmIssetPrintPackedArrayMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32556_isset_print_packed_array.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32556_isset_print_packed_array.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testVmPrintPackedArrayEmitsArrayToStringWarning(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'W:', $errstr, "\n";
    return true;
});
$a = [1];
print $a;
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32556_print_warn.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("W:Array to string conversion\nArray\n", $out);
    }

    public function testAotIssetPrintPackedArrayMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32556_isset_print_packed_array.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32556_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
