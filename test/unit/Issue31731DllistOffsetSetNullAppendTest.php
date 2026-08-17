<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplDoublyLinkedList::offsetSet(null) appends (php-src write_dimension; #31731).
 */
final class Issue31731DllistOffsetSetNullAppendTest extends TestCase
{
    public function testVmOffsetSetNullAppends(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedOutput(), $out);
    }

    public function testJitOffsetSetNullAppends(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedOutput(), $out);
    }

    private function expectedOutput(): string
    {
        return "[0]=a [1]=b [2]=NEW count=3\n"
            ."[0]=a [1]=b [2]=X [3]=Y count=4\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
$dll = new SplDoublyLinkedList();
$dll->push('a');
$dll->push('b');
$dll->offsetSet(null, 'NEW');
$parts = [];
for ($i = 0; $i < $dll->count(); $i++) {
    $parts[] = "[$i]=" . $dll[$i];
}
echo implode(' ', $parts) . ' count=' . $dll->count() . "\n";

$dll2 = new SplDoublyLinkedList();
$dll2->push('a');
$dll2->push('b');
$dll2[] = 'X';
$dll2[null] = 'Y';
$parts2 = [];
for ($i = 0; $i < $dll2->count(); $i++) {
    $parts2[] = "[$i]=" . $dll2[$i];
}
echo implode(' ', $parts2) . ' count=' . $dll2->count() . "\n";
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31731_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
