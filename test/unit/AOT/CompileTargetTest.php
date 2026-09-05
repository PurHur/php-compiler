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

    public function testReadElfMachineAndAssertObjectMatchesTarget(): void
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
        $mod = $ctx->moduleCreateWithName('elf-assert');
        $target->applyToModule($mod);
        $i32 = $ctx->int32Type();
        $fn = $mod->addFunction('main', $ctx->functionType($i32, false));
        $bb = $fn->appendBasicBlock('entry');
        $b = $ctx->builderCreate();
        $b->positionAtEnd($bb);
        $b->returnValue($i32->constInt(0, false));
        $out = sys_get_temp_dir().'/phpc-elf-assert-'.bin2hex(random_bytes(4)).'.o';
        $this->assertTrue($machine->emitToFile($mod, $out, $machine::CODEGEN_FILE_TYPE_OBJECT));
        $this->assertSame(183, CompileTarget::readElfMachine($out));
        $target->assertObjectMatchesTarget($out);

        $x86 = CompileTarget::resolve(CompileTarget::ID_X86_64_LINUX);
        $this->expectException(\RuntimeException::class);
        try {
            $x86->assertObjectMatchesTarget($out);
        } finally {
            @unlink($out);
        }
    }

    public function testReadElfMachineRejectsNonElf(): void
    {
        $path = sys_get_temp_dir().'/phpc-not-elf-'.bin2hex(random_bytes(4));
        file_put_contents($path, 'not an elf');
        $this->assertNull(CompileTarget::readElfMachine($path));
        @unlink($path);
    }

    /** Linux SPECS must carry crt + dynamic linker so Linker never host-fallbacks (#36391). */
    public function testLinuxTargetsCarryToolchainPathsAsData(): void
    {
        $x86 = CompileTarget::resolve(CompileTarget::ID_X86_64_LINUX);
        $this->assertSame('/usr/lib/x86_64-linux-gnu', $x86->crtDir());
        $this->assertSame('/lib64/ld-linux-x86-64.so.2', $x86->dynamicLinker());

        $arm = CompileTarget::resolve(CompileTarget::ID_AARCH64_LINUX);
        $this->assertSame('/usr/lib/aarch64-linux-gnu', $arm->crtDir());
        $this->assertSame('/lib/ld-linux-aarch64.so.1', $arm->dynamicLinker());

        $darwin = CompileTarget::resolve(CompileTarget::ID_AARCH64_DARWIN);
        $this->assertNull($darwin->crtDir());
        $this->assertNull($darwin->dynamicLinker());
    }

    /** Guard: Linker must not hardcode x86_64 crt/ld paths (#36391 Done-when). */
    public function testLinkerUsesCompileTargetNotHardcodedX86Toolchain(): void
    {
        $linker = file_get_contents(dirname(__DIR__, 3).'/lib/AOT/Linker.php');
        $this->assertNotFalse($linker);
        $this->assertStringNotContainsString(
            "?? '/usr/lib/x86_64-linux-gnu'",
            $linker,
            'Linker must not fall back to host x86_64 crt_dir'
        );
        $this->assertStringNotContainsString(
            "?? '/lib64/ld-linux-x86-64.so.2'",
            $linker,
            'Linker must not fall back to host x86_64 dynamic linker'
        );
        $this->assertStringContainsString('crt_dir + dynamic_linker in CompileTarget', $linker);
    }

    /**
     * Curated aarch64 seed: VM_* + lib_VM_* + ext/standard tiers — every unit.o
     * must be ELF e_machine=183. Empty / short seed is not a pass (#36391).
     */
    public function testCommittedAarch64SeedUnitIsEmAarch64(): void
    {
        $root = dirname(__DIR__, 3);
        $unitsDir = $root.'/prelinked/helper-runtime/aarch64-linux/units';
        $this->assertDirectoryExists($unitsDir);
        $vmDirs = glob($unitsDir.'/VM_*/unit.o') ?: [];
        $libVmDirs = glob($unitsDir.'/lib_VM_*/unit.o') ?: [];
        $extStdDirs = glob($unitsDir.'/ext_standard_*/unit.o') ?: [];
        $this->assertGreaterThanOrEqual(
            13,
            \count($vmDirs),
            'aarch64 seed must include the full VM_* set (see script/seed-aarch64-helper-runtime.sh)'
        );
        $this->assertGreaterThanOrEqual(
            9,
            \count($libVmDirs),
            'aarch64 seed must include the full lib_VM_* set (see script/seed-aarch64-helper-runtime.sh)'
        );
        $this->assertGreaterThanOrEqual(
            30,
            \count($extStdDirs),
            'aarch64 seed must include the ext/standard tiers (see script/seed-aarch64-helper-runtime.sh)'
        );
        $dirs = array_merge($vmDirs, $libVmDirs, $extStdDirs);
        $this->assertGreaterThanOrEqual(
            52,
            \count($dirs),
            'aarch64 seed must be VM_* + lib_VM_* + ext/standard (52); empty/short is not a pass'
        );
        $target = CompileTarget::resolve(CompileTarget::ID_AARCH64_LINUX);
        foreach ($dirs as $unit) {
            $this->assertSame(183, CompileTarget::readElfMachine($unit), $unit);
            $target->assertObjectMatchesTarget($unit);
        }
    }

    /** Seed refresh script must stay wired into multiarch release gate (#36391). */
    public function testAarch64SeedScriptIsWired(): void
    {
        $root = dirname(__DIR__, 3);
        $script = $root.'/script/seed-aarch64-helper-runtime.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = file_get_contents($script);
        $this->assertNotFalse($body);
        $this->assertStringContainsString('PHP_COMPILER_TARGET=aarch64-linux', $body);
        $this->assertStringContainsString('--check', $body);
        $this->assertStringContainsString('/VM/CoalesceJitHelper.php', $body);
        $this->assertStringContainsString('/lib/VM/ScalarDimFetchJitHelper.php', $body);
        $this->assertStringContainsString('lib_VM_', $body);
        $this->assertStringContainsString('/ext/standard/ArrayIsListJitHelper.php', $body);
        $this->assertStringContainsString('/ext/standard/PrintRJitHelper.php', $body);
        $check = $root.'/script/check-release-multiarch-helpers.sh';
        $gate = file_get_contents($check);
        $this->assertNotFalse($gate);
        $this->assertStringContainsString('seed-aarch64-helper-runtime.sh --check', $gate);
    }

    /**
     * Cross-host aot-smoke subset: KEEP_OBJECT emit + ELF e_machine gate (#36391).
     * Full link/run on arm64 still needs a native/QEMU runner.
     */
    public function testAotSmokeCrossEmitScriptIsWired(): void
    {
        $root = dirname(__DIR__, 3);
        $script = $root.'/script/aot-smoke-cross-emit.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'aot-smoke-cross-emit.sh must be executable');
        $body = file_get_contents($script);
        $this->assertNotFalse($body);
        $this->assertStringContainsString('PHP_COMPILER_KEEP_OBJECT_FILE=1', $body);
        $this->assertStringContainsString('readElfMachine', $body);
        $this->assertStringContainsString('empty result set is not a pass', $body);
        $this->assertStringContainsString('aarch64-linux', $body);
    }

    /** Doctor surface must name the active CompileTarget (#36391 Done-when). */
    public function testDoctorCheckCompileTargetMentionsTriple(): void
    {
        $root = dirname(__DIR__, 3);
        $doctor = file_get_contents($root.'/lib/Doctor.php');
        $this->assertNotFalse($doctor);
        $this->assertStringContainsString('checkCompileTarget', $doctor);
        $this->assertStringContainsString('triple=%s', $doctor);
        $this->assertStringContainsString('CompileTarget::current()', $doctor);
    }
}
