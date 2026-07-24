<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PackEngine;
use PHPCompiler\ext\standard\PackJitEngine;
use PHPCompiler\ext\standard\PackJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * pack() NestedJIT via JitVmHelperLink::ensureCompiled (#22842 / peer #22746 / #22602).
 */
final class PackJitRuntimeShrinkTest extends TestCase
{
    public function testPackJitRuntimeUsesStringPackNotStringPackJitMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PackJitRuntime.php');
        $this->assertStringContainsString('StringPack::ensureLinked', $runtime);
        $this->assertStringNotContainsString('StringPackJit::implement', $runtime);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPack.php');
        $this->assertStringContainsString('PackJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringContainsString('PackEngineEncode', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('StringPackJit', $bridge);
        $this->assertStringContainsString('PackJitEngine', (string) file_get_contents(__DIR__.'/../../ext/standard/PackJitHelper.php'));
    }

    public function testPackJitHelperAvoidsFunctionStaticNullDefault(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PackJitHelper.php');
        $this->assertStringContainsString('private static ?PackedArgvArrayMarker $arrayMarker', $source);
        $this->assertStringNotContainsString('static $marker = null', $source);
    }

    public function testPackJitHelperMatchesPackEngine(): void
    {
        $value = 0x1234;
        $this->assertSame(
            PackEngine::pack('n', [$value]),
            PackJitHelper::packArgv('n', \chr(1).\pack('q', $value))
        );
        $this->assertSame(
            PackJitEngine::pack('a3', ['hi']),
            PackJitHelper::packArgv('a3', \chr(4).\pack('q', 2).'hi')
        );
    }
}
