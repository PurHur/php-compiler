<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_http_build_query ABI shell from Builtin\Type (#33208).
 *
 * NestedJIT/AOT bridge stays StringHttpBuildQuery / HttpBuildQueryJitHelper / JitHttpBuildQuery.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint http_build_query.1 (#31894 / #32122).
 */
final class TypeDeadHttpBuildQueryAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnHttpBuildQueryAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33208', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_http_build_query[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_http_build_query (#33208)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_http_build_query'",
            $type,
            'Builtin\\Type must not always-register __compiler_http_build_query (#33208)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail_string still Type always-on; #33234 trigger_error / #33237 assert_fail dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail_string'", $type);
        $this->assertStringContainsString('StringHttpBuildQuery', $type);
    }

    public function testRuntimeOwnerDeclaresHttpBuildQueryAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHttpBuildQuery.php');
        $this->assertStringContainsString('#33208', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementBuildBridge', $owner);
        $this->assertStringContainsString('__compiler_http_build_query', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/HttpBuildQueryJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitHttpBuildQuery.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHttpBuildQuery.php');
        $this->assertStringContainsString('#33208', $jit);
        $this->assertStringContainsString('StringHttpBuildQuery::ensureLinked', $jit);
    }

    public function testStringBuiltinStillImplementsHttpBuildQueryOnFullLoad(): void
    {
        $string = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('StringHttpBuildQuery::implement($this->context)', $string);
    }

    public function testNoNewRuntimeCForHttpBuildQueryAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/http_build_query.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/http_build_query.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_http_build_query.c');
    }
}
