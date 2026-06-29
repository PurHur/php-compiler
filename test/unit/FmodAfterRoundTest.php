<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** fmod() first arg after sibling FuncCall — hoisted UnaryMinus wiring (#13508). */
final class FmodAfterRoundTest extends TestCase
{
    public function testFmodNegAfterRoundVm(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/issue_fmod_after_round.php';
        $out = shell_exec('php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null');
        self::assertSame("fmod_neg=-0.3\n", $out);
    }

    public function testFmodNegAfterRoundJit(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = $root.'/bin/jit.php';
        if (!is_file($jit)) {
            self::markTestSkipped('bin/jit.php missing');
        }
        $path = $root.'/test/repro/issue_fmod_after_round.php';
        $out = shell_exec('php '.escapeshellarg($jit).' '.escapeshellarg($path).' 2>/dev/null');
        self::assertSame("fmod_neg=-0.3\n", $out);
    }
}
