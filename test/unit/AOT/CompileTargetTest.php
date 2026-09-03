<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #36391: PHP_COMPILER_TARGET selects helper-cache arch + Linker toolchain data.
 */
final class CompileTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearTargetEnv();
    }

    protected function tearDown(): void
    {
        $this->clearTargetEnv();
        parent::tearDown();
    }

    private function clearTargetEnv(): void
    {
        putenv('PHP_COMPILER_TARGET');
        unset($_ENV['PHP_COMPILER_TARGET'], $_SERVER['PHP_COMPILER_TARGET']);
        CompileTarget::resetCache();
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

    public function testLlvmTargetNameAndRelocConsts(): void
    {
        $x86 = CompileTarget::resolve(CompileTarget::ID_X86_64_LINUX);
        $this->assertSame('x86-64', $x86->llvmTargetName());
        $this->assertSame('X86', $x86->llvmBackendName());
        $this->assertSame(62, $x86->elfMachine());
        $this->assertSame(\PHPLLVM\Target::RELOC_PIC, $x86->llvmRelocModeConst());

        $arm = CompileTarget::resolve(CompileTarget::ID_AARCH64_LINUX);
        $this->assertSame('aarch64', $arm->llvmTargetName());
        $this->assertSame('AArch64', $arm->llvmBackendName());
        $this->assertSame(183, $arm->elfMachine());
    }

    public function testCreateTargetMachineBindingEmitsObject(): void
    {
        if (!\PHPCompiler\LlvmToolchain::isReady(dirname(__DIR__, 3))) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $llvm = \PHPLLVM\Chooser::choose();
        $llvm->initializeNative();
        $target = CompileTarget::current();
        $llvmTarget = $llvm->getTargetFromName($target->llvmTargetName());
        $machine = $llvmTarget->createTargetMachine(
            $target->llvmTriple(),
            $target->cpu(),
            '',
            \PHPLLVM\Target::OPT_LEVEL_NONE,
            $target->llvmRelocModeConst(),
            \PHPLLVM\Target::CODE_MODEL_DEFAULT
        );
        $this->assertInstanceOf(\PHPLLVM\TargetMachine::class, $machine);

        $ctx = $llvm->contextCreate();
        $mod = $ctx->moduleCreateWithName('create-tm-test');
        $i32 = $ctx->int32Type();
        $fn = $mod->addFunction('main', $ctx->functionType($i32, false));
        $bb = $fn->appendBasicBlock('entry');
        $b = $ctx->builderCreate();
        $b->positionAtEnd($bb);
        $b->returnValue($i32->constInt(0, false));
        $out = sys_get_temp_dir().'/phpc-create-tm-'.bin2hex(random_bytes(4)).'.o';
        $this->assertTrue($machine->emitToFile($mod, $out, $machine::CODEGEN_FILE_TYPE_OBJECT));
        $this->assertFileExists($out);
        $this->assertGreaterThan(0, filesize($out));
        $hdr = file_get_contents($out, false, null, 0, 20);
        $this->assertNotFalse($hdr);
        $this->assertSame("\x7fELF", substr($hdr, 0, 4));
        $want = $target->elfMachine();
        if (null !== $want) {
            $this->assertSame($want, unpack('v', substr($hdr, 18, 2))[1]);
        }
        @unlink($out);
    }

    public function testConfigRegistryListsCodegenOptKnob(): void
    {
        $reg = \PHPCompiler\Config::registry();
        $this->assertArrayHasKey('PHP_COMPILER_AOT_CODEGEN_OPT', $reg);
        $this->assertSame('#36387', $reg['PHP_COMPILER_AOT_CODEGEN_OPT']['since']);
    }

    /**
     * Cross-target object emit must produce aarch64 ELF, not host x86_64 via MCJIT fallback (#36391).
     */
    public function testAarch64TargetMachineEmitsAarch64ElfNotHost(): void
    {
        if (!\PHPCompiler\LlvmToolchain::isReady(dirname(__DIR__, 3))) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $llvm = \PHPLLVM\Chooser::choose();
        $target = CompileTarget::resolve(CompileTarget::ID_AARCH64_LINUX);
        try {
            $target->initializeLlvm($llvm);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $llvmTarget = $llvm->getTargetFromName($target->llvmTargetName());
        $machine = $llvmTarget->createTargetMachine(
            $target->llvmTriple(),
            $target->cpu(),
            '',
            \PHPLLVM\Target::OPT_LEVEL_NONE,
            $target->llvmRelocModeConst(),
            \PHPLLVM\Target::CODE_MODEL_DEFAULT
        );
        $ctx = $llvm->contextCreate();
        $mod = $ctx->moduleCreateWithName('aarch64-cross-emit');
        $target->applyToModule($mod);
        $i32 = $ctx->int32Type();
        $fn = $mod->addFunction('main', $ctx->functionType($i32, false));
        $bb = $fn->appendBasicBlock('entry');
        $b = $ctx->builderCreate();
        $b->positionAtEnd($bb);
        $b->returnValue($i32->constInt(0, false));
        $out = sys_get_temp_dir().'/phpc-aarch64-tm-'.bin2hex(random_bytes(4)).'.o';
        $this->assertTrue($machine->emitToFile($mod, $out, $machine::CODEGEN_FILE_TYPE_OBJECT));
        $this->assertFileExists($out);
        $hdr = file_get_contents($out, false, null, 0, 20);
        $this->assertNotFalse($hdr);
        $this->assertGreaterThanOrEqual(20, strlen($hdr));
        $this->assertSame("\x7fELF", substr($hdr, 0, 4));
        $eMachine = unpack('v', substr($hdr, 18, 2))[1];
        $this->assertSame(
            183,
            $eMachine,
            'cross emit must be EM_AARCH64=183, not host EM_X86_64=62 (got '.$eMachine.')'
        );
        @unlink($out);
    }

    public function testCrossTargetRefusesLinkOnX86Host(): void
    {
        if (CompileTarget::hostId() === CompileTarget::ID_AARCH64_LINUX) {
            $this->markTestSkipped('native aarch64 may link');
        }
        $t = CompileTarget::resolve(CompileTarget::ID_AARCH64_LINUX);
        $this->assertFalse($t->canLinkOnThisHost());
        $this->expectException(\RuntimeException::class);
        $t->assertCanLinkOnThisHost();
    }
}
