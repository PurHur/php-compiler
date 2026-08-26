<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StringJsonEncode /
 * StringJsonDecode / ScalarDimFetchRuntime / StringOffsetRuntime (#35065 / peer #35035).
 *
 * Full standalone must not NestedJIT json_* / string-offset / scalar-dim during init
 * (#32122 .1 mint class). Call sites already ensureLinked before lookup / emit.
 */
final class ContextFullStandaloneLazyJsonOffsetShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerJsonAndOffsetNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35065', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StringJsonEncode::ensureStandaloneBodies($this)',
            'StringJsonDecode::ensureStandaloneBodies($this)',
            'ScalarDimFetchRuntime::ensureStandaloneBodies($this)',
            'StringOffsetRuntime::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35065)'
            );
        }

        // Still links refresh used by inventory / standalone main.
        // StringStrspn left ensureFull in #35089 — do not re-assert eager strspn here.
        // StringFormat left ensureFull in #35130 — do not re-assert eager format here.
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $encode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonEncode.php');
        $this->assertStringContainsString('StringJsonEncode::ensureLinked($context)', $encode);

        $decode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonDecode.php');
        $this->assertStringContainsString('StringJsonDecode::ensureLinked($context)', $decode);

        $scalar = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScalarDimFetchRuntime.php');
        $this->assertStringContainsString('self::ensureLinked($context)', $scalar);
        $this->assertStringContainsString('#35065', $scalar);

        $offset = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringOffsetRuntime.php');
        $this->assertStringContainsString('self::ensureLinked($context)', $offset);
        $this->assertStringContainsString('#35065', $offset);
    }

    public function testJsonRuntimesDocumentLazyFull(): void
    {
        $encode = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('#35065', $encode);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $encode);

        $decode = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('#35065', $decode);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $decode);
    }

    public function testNoNewRuntimeCForFullStandaloneLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/json_encode.c',
            'must not add json_encode.c for #35065 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/string_offset.c',
            'must not add string_offset.c for #35065'
        );
    }
}
