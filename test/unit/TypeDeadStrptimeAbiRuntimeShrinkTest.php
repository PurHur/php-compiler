<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_strptime ABI shell from Builtin\Type (#33224).
 *
 * NestedJIT/AOT bridge stays StringStrptime / StrptimeJitHelper / JitStrptime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint strptime.1 (#31894 / #32122).
 */
final class TypeDeadStrptimeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStrptimeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33224', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_strptime[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_strptime (#33224)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_strptime'",
            $type,
            'Builtin\\Type must not always-register __compiler_strptime (#33224)'
        );
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString('StringStrptime', $type);
    }

    public function testRuntimeOwnerDeclaresStrptimeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrptime.php');
        $this->assertStringContainsString('#33224', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementStrptimeBridge', $owner);
        $this->assertStringContainsString('__compiler_strptime', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StrptimeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStrptime.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrptime.php');
        $this->assertStringContainsString('#33224', $jit);
        $this->assertStringContainsString('StringStrptime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringStrptime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStrptime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStrptimeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/strptime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/strptime.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_strptime.c');
    }
}
