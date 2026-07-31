<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UnpackEngine;
use PHPCompiler\ext\standard\UnpackJitHelper;
use PHPCompiler\ext\standard\VmPack;
use PHPUnit\Framework\TestCase;

/** unpack() JIT routes through UnpackJitHelper PHP not StringUnpackJit LLVM monolith (#9543). */
final class UnpackJitRuntimeShrinkTest extends TestCase
{
    public function testUnpackJitRuntimeUsesStringUnpackNotStringUnpackJitMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UnpackJitRuntime.php');
        $this->assertStringContainsString('StringUnpack::ensureLinked', $runtime);
        $this->assertStringNotContainsString('StringUnpackJit::implement', $runtime);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnpack.php');
        $this->assertStringContainsString('UnpackJitHelper', $bridge);
        $this->assertStringNotContainsString('StringUnpackJit', $bridge);
        $this->assertStringContainsString('VmPack::unpackToHashTable', (string) file_get_contents(__DIR__.'/../../ext/standard/UnpackJitHelper.php'));
        $this->assertStringNotContainsString('emitUnpack', $bridge);
    }

    public function testStringUnpackRoutesThroughEnsureCompiledBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnpack.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('UnpackEngine.php', $source);
        $this->assertStringContainsString('UnpackJitHelper::unpackArgv', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testUnpackJitHelperMatchesUnpackEngine(): void
    {
        $packed = \pack('i', -1);
        $engine = UnpackEngine::unpack('i', $packed);
        $this->assertIsArray($engine);
        $ht = UnpackJitHelper::unpackArgv('i', $packed, 0);
        $this->assertNotNull($ht);
        $this->assertSame(1, $ht->getNumElements());
        $this->assertSame($engine, VmPack::unpack('i', $packed, 0));

        $this->assertNull(UnpackJitHelper::unpackArgv('Z', '', 0));
    }
}
