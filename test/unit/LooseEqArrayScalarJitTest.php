<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #5033 */
final class LooseEqArrayScalarJitTest extends TestCase
{
    private const PHPT = __DIR__.'/../compliance/cases/language/loose_eq_array_scalar.phpt';

    public function testVmPhptMatchesZend(): void
    {
        $this->runPhpt('bin/vm.php');
    }

    public function testJitPhptMatchesZend(): void
    {
        $this->runPhpt('bin/jit.php');
    }

    private function runPhpt(string $bin): void
    {
        $sections = [];
        $section = '';
        foreach (file(self::PHPT) as $line) {
            if (preg_match('/^--([_A-Z]+)--/', $line, $m)) {
                $section = $m[1];
                $sections[$section] = '';
                continue;
            }
            if ('' !== $section) {
                $sections[$section] .= $line;
            }
        }
        $code = $sections['FILE'] ?? '';
        $expect = preg_replace('/\r\n?/', "\n", trim($sections['EXPECT'] ?? '')) ?? '';

        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            ['php', $repo.'/'.$bin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $err));
        $this->assertSame($expect, preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '');
    }
}
