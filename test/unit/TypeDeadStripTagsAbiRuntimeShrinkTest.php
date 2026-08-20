<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on strip_tags ABI shell from Builtin\Type (#32971).
 *
 * NestedJIT/AOT bridge stays StringStripTags + StripTagsJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint strip_tags.1 (#31894 / #32122).
 */
final class TypeDeadStripTagsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStripTagsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32971', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_strip_tags[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_strip_tags (#32971)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_strip_tags'",
            $type,
            'Builtin\\Type must not always-register __compiler_strip_tags (#32971)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_fopen'", $type);
        $this->assertStringContainsString('StringStripTags::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresStripTagsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStripTags.php');
        $this->assertStringContainsString('#32971', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('__compiler_strip_tags', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StripTagsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/strip_tags.php');
    }

    public function testTypeInitializeStillEnsureLinksStripTagsRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringStripTags::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForStripTagsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/strip_tags.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/strip_tags.c');
    }
}
