<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #10459 */
final class StreamSetChunkSizeTest extends TestCase
{
    public function testStreamSetChunkSizeOnPhpMemoryReturnsPreviousDefault(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$handle = fopen('php://memory', 'r+');
echo stream_set_chunk_size($handle, 8192), "\n";
echo stream_set_chunk_size($handle, 4096), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'stream_set_chunk_size_memory.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame("8192\n8192\n", ob_get_clean());
    }

    public function testStreamSetChunkSizeRejectsNonPositiveSize(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$handle = fopen('php://memory', 'r+');
try {
    stream_set_chunk_size($handle, 0);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'stream_set_chunk_size_zero.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "stream_set_chunk_size(): Argument #2 (\$size) must be greater than 0\n",
            ob_get_clean()
        );
    }
}
