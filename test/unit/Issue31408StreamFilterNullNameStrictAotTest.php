<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stream_filter_append/prepend null $filter_name under strict_types → TypeError (#31408).
 *
 * php-src: ext/standard/streamsfuncs.c / basic_functions.stub.php — string $filter_name
 *
 * @group llvm
 * @group aot
 */
final class Issue31408StreamFilterNullNameStrictAotTest extends TestCase
{
    public function testAotNullFilterNameStrictTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31408_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_31408_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
declare(strict_types=1);
$h = fopen('php://memory', 'r+');
try {
    stream_filter_append($h, null);
    echo "NO_THROW_APPEND\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    stream_filter_prepend($h, null);
    echo "NO_THROW_PREPEND\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
fclose($h);
PHP);
        $compile = escapeshellarg(PHP_BINARY).' '
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
                $joined = implode("\n", $runOut)."\n";
                $this->assertSame(
                    "TypeError: stream_filter_append(): Argument #2 (\$filter_name) must be of type string, null given\n"
                    ."TypeError: stream_filter_prepend(): Argument #2 (\$filter_name) must be of type string, null given\n",
                    $joined
                );
                $this->assertStringNotContainsString('unable to locate filter', $joined);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
