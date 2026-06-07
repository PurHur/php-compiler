<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** readline() native STDIN fallback when host ext/readline is absent (#6216). */
final class ReadlineStdinFallbackTest extends TestCase
{
    public function testPipedStdinReturnsLineWithoutHostReadline(): void
    {
        $root = dirname(__DIR__, 2);
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $vm = $root . '/bin/vm.php';
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $vm, '-r', 'echo readline();'], $descriptor, $pipes, $root);
        $this->assertIsResource($proc);
        fwrite($pipes[0], "hello\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stderr ?: 'VM subprocess failed');
        $this->assertSame('hello', trim($stdout));
    }
}
