<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_walk() VM smoke (#1209).
 */
final class ArrayWalkBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$items = ['a', 'b'];
$ok = array_walk($items, 'strtoupper');
echo $ok ? 'ok' : 'fail', "\n";
echo $items[0], '|', $items[1], "\n";
PHP;

    private const EXPECT = <<<'TXT'
ok
A|B
TXT;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_walk_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '';
    }
}
