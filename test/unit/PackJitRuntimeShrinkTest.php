<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PackEngine;
use PHPCompiler\ext\standard\PackEngineEncode;
use PHPCompiler\ext\standard\PackJitEngine;
use PHPCompiler\ext\standard\PackJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * pack() NestedJIT via JitVmHelperLink::ensureCompiledBundle (#22842 / #22981).
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
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $bridge);
        $this->assertStringContainsString('PACK_HELPER_BUNDLE', $bridge);
        $this->assertStringContainsString('PACK_HELPER_FILE', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringContainsString('PackEngineEncode', $bridge);
        // Not a corpus unit — emit discovers only *HELPER_PATH constants (#22981).
        $this->assertStringContainsString('PACK_HELPER_FILE', $bridge);
        $this->assertStringNotContainsString('private const HELPER_PATH', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('StringPackJit', $bridge);
        $this->assertStringContainsString('PackJitEngine', (string) file_get_contents(__DIR__.'/../../ext/standard/PackJitHelper.php'));
    }

    public function testPackJitHelperIsNotHelperRuntimeCorpusUnit(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPack.php');
        $this->assertStringContainsString('not a helper-runtime corpus unit', $bridge);
        $this->assertMatchesRegularExpression(
            '/PACK_HELPER_BUNDLE\s*=\s*\[[^\]]*'
            .'IEEE_PATH[^\]]*'
            .'ENCODE_PATH[^\]]*'
            .'ENGINE_PATH[^\]]*'
            .'PACK_HELPER_FILE/s',
            $bridge
        );
    }

    public function testPackJitHelperAvoidsFunctionStaticNullDefault(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PackJitHelper.php');
        $this->assertStringContainsString('private static ?PackedArgvArrayMarker $arrayMarker', $source);
        $this->assertStringNotContainsString('static $marker = null', $source);
    }

    public function testPackEngineEncodeAvoidsHostPackBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PackEngineEncode.php');
        $this->assertStringNotContainsString('$bytes = \\pack(', $source);
        $this->assertStringNotContainsString("\\unpack('S'", $source);
        $this->assertStringContainsString('#22981', $source);
        $this->assertStringContainsString('Manual two\'s-complement bytes', $source);
    }

    public function testPackEngineEncodePutLongMatchesHostPack(): void
    {
        $le = PackEngineEncode::machineLe();
        $this->assertTrue($le, 'helper-runtime arches are little-endian');
        foreach ([0, 1, -1, 0x7F, -128, 0x1234, -0x1234, 0x12345678, -0x12345678] as $value) {
            $this->assertSame(\pack('c', $value), PackEngineEncode::putLong($value, 1, $le), "c {$value}");
            $this->assertSame(\pack('s', $value), PackEngineEncode::putLong($value, 2, $le), "s {$value}");
            $this->assertSame(\pack('l', $value), PackEngineEncode::putLong($value, 4, $le), "l {$value}");
            $this->assertSame(\pack('q', $value), PackEngineEncode::putLong($value, 8, $le), "q {$value}");
        }
        $this->assertSame("\x12\x34", PackEngineEncode::putLong(0x1234, 2, false));
        $this->assertSame("\x34\x12", PackEngineEncode::putLong(0x1234, 2, true));
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
