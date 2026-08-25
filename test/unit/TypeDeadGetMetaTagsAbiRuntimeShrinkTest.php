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
 * Thin AOT links lazily via JitGetMetaTags::ensureLinked (#34578 / peer #34423) —
 * Context::ensureMinimalUserStandaloneBodies no longer always-on MetaTags.
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
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
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

    public function testTypeInitializeDropsEagerGetMetaTagsEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString(
            'MetaTagsRuntime::ensureLinked($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly MetaTagsRuntime::ensureLinked($this->context) (#34423)'
        );
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);
        $this->assertStringNotContainsString(
            'MetaTagsRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly MetaTags (#34578)'
        );
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetMetaTags.php');
        $this->assertStringContainsString(
            'MetaTagsRuntime::ensureLinked',
            $jit,
            'JitGetMetaTags must ensureLinked before lookup (#34578)'
        );
    }

    public function testNoNewRuntimeCForGetMetaTagsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/get_meta_tags.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/get_meta_tags.c');
    }
}
