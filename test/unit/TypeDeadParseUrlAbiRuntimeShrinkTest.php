<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __phpc_parse_url_* ABI shells from Builtin\Type (#33236).
 *
 * NestedJIT/AOT bridge stays ParseUrlRuntime / JitParseUrl / ParseUrlJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first, then addFunction if absent)
 * so leftover Type empty decls cannot mint parse_url_component.1 (#31894 / #32122).
 */
final class TypeDeadParseUrlAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnParseUrlAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33236', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_parse_url_component[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __phpc_parse_url_component (#33236)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_parse_url_assoc[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __phpc_parse_url_assoc (#33236)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__phpc_parse_url_component'",
            $type,
            'Builtin\\Type must not always-register __phpc_parse_url_component (#33236)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__phpc_parse_url_assoc'",
            $type,
            'Builtin\\Type must not always-register __phpc_parse_url_assoc (#33236)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (stream_path still Type always-on; #33236 parse_url dropped).
        $this->assertStringContainsString("registerFunction('__phpc_stream_path'", $type);
        $this->assertStringContainsString('ParseUrlRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresParseUrlAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlRuntime.php');
        $this->assertStringContainsString('#33236', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('scopeLoweringToFunction', $owner);
        $this->assertStringContainsString('__phpc_parse_url_component', $owner);
        $this->assertStringContainsString('__phpc_parse_url_assoc', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ParseUrlJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitParseUrl.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseUrl.php');
        $this->assertStringContainsString('#33236', $jit);
        $this->assertStringContainsString('ParseUrlRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksParseUrlRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('ParseUrlRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForParseUrlAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/parse_url.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/parse_url.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_parse_url.c');
    }
}
