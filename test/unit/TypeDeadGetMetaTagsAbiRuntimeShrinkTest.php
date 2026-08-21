<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_get_meta_tags ABI shell from Builtin\Type (#33035).
 *
 * NestedJIT/AOT bridge stays MetaTagsRuntime + MetaTagsJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint get_meta_tags.1 (#31894 / #32122).
 * Thin standalone AOT also links via Context::ensureStandaloneBodies (#33051 / #33030).
 */
final class TypeDeadGetMetaTagsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnGetMetaTagsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33035', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_get_meta_tags[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_get_meta_tags (#33035)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_get_meta_tags'",
            $type,
            'Builtin\\Type must not always-register __compiler_get_meta_tags (#33035)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (proc_open still Type always-on).
        $this->assertStringContainsString("registerFunction('__compiler_proc_open'", $type);
        $this->assertStringContainsString('MetaTagsRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresGetMetaTagsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('#33051', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('i64ToTypedPtr', $owner);
        $this->assertStringContainsString('ensureNativeHtInternalProxies', $owner);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $owner);
        $this->assertStringContainsString('__compiler_get_meta_tags', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/MetaTagsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetMetaTags.php');
    }

    public function testTypeInitializeStillEnsureLinksGetMetaTagsRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('MetaTagsRuntime::ensureLinked($this->context)', $type);
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('MetaTagsRuntime::ensureStandaloneBodies($this)', $context);
    }

    public function testNoNewRuntimeCForGetMetaTagsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/get_meta_tags.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/get_meta_tags.c');
    }
}
