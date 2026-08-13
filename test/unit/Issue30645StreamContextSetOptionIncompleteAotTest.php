<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_context_set_option incomplete string form → ValueError (#30645).
 *
 * @group llvm
 * @group aot
 */
final class Issue30645StreamContextSetOptionIncompleteAotTest extends TestCase
{
    public function testAotIncompleteStringFormThrowsValueError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30645_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30645_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
$c = stream_context_create();
try {
    stream_context_set_option($c, 'http');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    stream_context_set_option($c, 'http', 'method');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(stream_context_set_option($c, 'http', 'method', 'GET'));
echo "\n";
PHP);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertSame(0, $runRc, $joined);
            $this->assertStringContainsString(
                'ValueError: stream_context_set_option(): Argument #3 ($option_name) cannot be null when argument #2 ($wrapper_or_options) is a string',
                $joined
            );
            $this->assertStringContainsString(
                'ValueError: stream_context_set_option(): Argument #4 ($value) must be provided when argument #2 ($wrapper_or_options) is a string',
                $joined
            );
            $this->assertStringContainsString('true', $joined);
            $this->assertStringNotContainsString('NO_THROW', $joined);
            $this->assertStringNotContainsString('LogicException', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
