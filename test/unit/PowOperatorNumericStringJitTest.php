<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #5005 */
final class PowOperatorNumericStringJitTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php
echo (2 ** "3"), "\n";
echo ("2" ** 3), "\n";
PHP;

    private const EXPECT = <<<'TXT'
8
8
TXT;

    public function testVmMatchesZendSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    public function testJitMatchesZendSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/jit.php'));
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            ['php', $repo.'/'.$bin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fwrite($pipes[0], self::CODE);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '';
    }
}
