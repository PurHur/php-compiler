<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_random_bytes ABI shell from Builtin\Type (#33160).
 *
 * NestedJIT/AOT bridge stays StringRandomBytes / RandomBytesJitHelper (ensureBridge).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint random_bytes.1 (#31894 / #32122).
 */
final class TypeDeadRandomBytesAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnRandomBytesAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33160', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_random_bytes[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_random_bytes (#33160)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_random_bytes'",
            $type,
            'Builtin\\Type must not always-register __compiler_random_bytes (#33160)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
    }

    public function testRuntimeOwnerDeclaresRandomBytesAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRandomBytes.php');
        $this->assertStringContainsString('#33160', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('ensureBridge', $owner);
        $this->assertStringContainsString('__compiler_random_bytes', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/RandomBytesJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitRandomBytes.php');
    }

    public function testCallSiteEnsureLinksStringRandomBytes(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('StringRandomBytes::ensureLinked($this->context)', $type);
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRandomBytes.php');
        $this->assertStringContainsString('StringRandomBytes::ensureLinked', $owner);
    }

    public function testNoNewRuntimeCForRandomBytesAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/random_bytes.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/random_bytes.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_random_bytes.c');
    }
}
