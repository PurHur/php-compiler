<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bundled Compiler.php native link + run (issues #212, #78).
 *
 * @group aot-link
 */
final class CompilerSelfhostLinkTest extends TestCase
{
    public function testBundledCompilerMinimalLinkAndRun(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-selfhost-link.sh';
        $this->assertFileExists($script);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script], $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if (2 === $exit) {
            $this->markTestSkipped('LLVM 9 not available for native link');
        }

        $this->assertSame(
            0,
            $exit,
            trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''))
        );
        $this->assertStringContainsString('bootstrap-selfhost-link: OK', $stdout !== false ? $stdout : '');
    }
}
