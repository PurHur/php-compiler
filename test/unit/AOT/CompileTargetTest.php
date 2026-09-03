<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #36391: PHP_COMPILER_TARGET selects helper-cache arch + Linker toolchain data.
 */
final class CompileTargetTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_TARGET');
        unset($_ENV['PHP_COMPILER_TARGET'], $_SERVER['PHP_COMPILER_TARGET']);
        CompileTarget::resetCache();
        parent::tearDown();
    }

    public function testDefaultMatchesHostId(): void
    {
        CompileTarget::resetCache();
        $t = CompileTarget::current();
        $this->assertSame(CompileTarget::hostId(), $t->id());
        $this->assertTrue($t->isHostNative());
        $this->assertSame(HelperRuntimeCache::archKey(), $t->id());
        if (CompileTarget::ID_X86_64_LINUX === $t->id()) {
            $this->assertSame('x86_64-unknown-linux-gnu', $t->llvmTriple());
            $this->assertSame('-L/usr/lib/x86_64-linux-gnu', $t->hostLibSearchFlag());
            $this->assertTrue($t->canLinkOnThisHost());
        }
    }

    public function testEnvSelectsAarch64LinuxHelperCacheDir(): void
    {
        putenv('PHP_COMPILER_TARGET=aarch64-linux');
        $_ENV['PHP_COMPILER_TARGET'] = 'aarch64-linux';
        CompileTarget::resetCache();
        $t = CompileTarget::current();
        $this->assertSame(CompileTarget::ID_AARCH64_LINUX, $t->id());
        $this->assertSame('aarch64-unknown-linux-gnu', $t->llvmTriple());
        $this->assertSame('/usr/lib/aarch64-linux-gnu', $t->multiarchLibDir());
        $this->assertSame($t->id() === CompileTarget::hostId(), $t->isHostNative());
        $this->assertSame($t->isHostNative(), $t->canLinkOnThisHost());
        $this->assertSame('aarch64-linux', HelperRuntimeCache::archKey());
        if (!$t->canLinkOnThisHost()) {
            $this->expectException(\RuntimeException::class);
            $t->assertCanLinkOnThisHost();
        } else {
            $this->assertTrue(true); // native aarch64 host may link
        }
    }

    public function testArm64AliasNormalizesToAarch64Darwin(): void
    {
        $t = CompileTarget::resolve('arm64-darwin');
        $this->assertSame(CompileTarget::ID_AARCH64_DARWIN, $t->id());
        $this->assertFalse($t->canLinkOnThisHost());
        $this->assertNull($t->multiarchLibDir());
    }

    public function testUnknownExplicitTargetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CompileTarget::resolve('riscv64-linux');
    }

    public function testConfigRegistryListsTargetKnob(): void
    {
        $reg = \PHPCompiler\Config::registry();
        $this->assertArrayHasKey('PHP_COMPILER_TARGET', $reg);
        $this->assertSame('#36391', $reg['PHP_COMPILER_TARGET']['since']);
    }
}
