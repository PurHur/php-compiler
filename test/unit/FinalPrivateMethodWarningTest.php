<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6914 */
final class FinalPrivateMethodWarningTest extends TestCase
{
    public function testFinalPrivateMethodEmitsStderrWarningAndContinues(): void
    {
        $code = <<<'PHP'
<?php
class C { final private function m(): void {} }
echo "compiled\n";
PHP;
        $repoRoot = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'fpw');
        if (false === $tmp) {
            $this->fail('tempnam failed');
        }
        $script = $tmp . '.php';
        rename($tmp, $script);
        file_put_contents($script, $code);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            ['php', $repoRoot . '/bin/vm.php', $script],
            $descriptors,
            $pipes,
            $repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($script);
        $this->assertStringContainsString(
            'Private methods cannot be final as they are never overridden by other classes',
            (string) $stderr
        );
        $this->assertSame("compiled\n", $stdout);
    }

    public function testFinalPublicMethodDoesNotWarn(): void
    {
        $code = <<<'PHP'
<?php
class C { final public function m(): void {} }
echo "ok\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'final_public.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
