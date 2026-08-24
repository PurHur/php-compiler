<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on version_compare ABI shell from Builtin\Type (#32843).
 *
 * User-script version_compare() stays JitInfo / VersionCompareJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint version_compare.1 (#31894 / #32122).
 */
final class TypeDeadVersionCompareAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnVersionCompareAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32843', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_version_compare[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_version_compare (#32843)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_version_compare'",
            $type,
            'Builtin\\Type must not always-register __compiler_version_compare (#32843)'
        );
        $this->assertStringNotContainsString(
            "'__compiler_version_compare' =>",
            $type,
            'Builtin\\Type must not always-declare __compiler_version_compare in a table (#32843)'
        );
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        // StringVersionCompare ensureLinked moved to call-site (#34337).
        $this->assertStringContainsString('#34337', $type);
        $this->assertStringNotContainsString('StringVersionCompare::ensureLinked($this->context)', $type);
    }

    public function testRuntimeOwnerDeclaresVersionCompareAbiModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('#32843', $svc);
        $this->assertStringContainsString("getNamedFunction(self::ABI)", $svc);
        $this->assertStringContainsString('__compiler_version_compare', $svc);
        $this->assertStringContainsString('module->addFunction(', $svc);
    }

    public function testTypeInitializeDoesNotEagerlyEnsureLinkStringVersionCompare(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('StringVersionCompare::ensureLinked($this->context)', $type);
        $jitInfo = (string) file_get_contents(__DIR__.'/../../ext/standard/JitInfo.php');
        $this->assertStringContainsString('StringVersionCompare::ensureLinked', $jitInfo);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltin(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitInfo.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/VersionCompareJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/version_compare.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_version_compare.c'
        );
    }
}
