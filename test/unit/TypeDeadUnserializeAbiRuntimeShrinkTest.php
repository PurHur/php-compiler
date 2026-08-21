<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_unserialize ABI shell from Builtin\Type (#33214).
 *
 * NestedJIT/AOT bridge stays StringUnserialize / UnserializeJitHelper / JitUnserialize.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint unserialize.1 (#31894 / #32122).
 */
final class TypeDeadUnserializeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnUnserializeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33214', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_unserialize[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_unserialize (#33214)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_unserialize'",
            $type,
            'Builtin\\Type must not always-register __compiler_unserialize (#33214)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33214 unserialize dropped).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StringUnserialize::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresUnserializeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('#33214', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementUnserializeBridge', $owner);
        $this->assertStringContainsString('__compiler_unserialize', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/UnserializeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitUnserialize.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUnserialize.php');
        $this->assertStringContainsString('#33214', $jit);
        $this->assertStringContainsString('StringUnserialize::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringUnserialize(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringUnserialize::ensureLinked($this->context)', $type);
    }

    public function testStringBuiltinStillImplementsUnserializeOnFullLoad(): void
    {
        $string = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('StringUnserialize::implement($this->context)', $string);
    }

    public function testNoNewRuntimeCForUnserializeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/unserialize.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/unserialize.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_unserialize.c');
    }
}
