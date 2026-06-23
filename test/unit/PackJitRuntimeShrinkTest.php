<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PackEngine;
use PHPCompiler\ext\standard\PackJitHelper;
use PHPUnit\Framework\TestCase;

/** pack() JIT routes through PackJitHelper PHP not StringPackJit LLVM monolith (#9133). */
final class PackJitRuntimeShrinkTest extends TestCase
{
    public function testPackJitRuntimeUsesStringPackNotStringPackJitMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PackJitRuntime.php');
        $this->assertStringContainsString('StringPack::ensureLinked', $runtime);
        $this->assertStringNotContainsString('StringPackJit::implement', $runtime);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPack.php');
        $this->assertStringContainsString('PackJitHelper', $bridge);
        $this->assertStringContainsString('PackEngine', (string) file_get_contents(__DIR__.'/../../ext/standard/PackJitHelper.php'));
        $this->assertStringNotContainsString('emitPack', $bridge);
    }

    public function testPackJitHelperMatchesPackEngine(): void
    {
        $value = 0x1234;
        $this->assertSame(
            PackEngine::pack('n', [$value]),
            PackJitHelper::packArgv('n', \chr(1).\pack('q', $value))
        );
        $this->assertSame(
            PackEngine::pack('a3', ['hi']),
            PackJitHelper::packArgv('a3', \chr(4).\pack('q', 2).'hi')
        );
    }
}
