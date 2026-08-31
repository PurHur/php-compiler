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
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('StringHttpBuildQuery', $type);
    }

    public function testRuntimeOwnerDeclaresHttpBuildQueryAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHttpBuildQuery.php');
        $this->assertStringContainsString('#33208', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementBuildBridge', $owner);
        $this->assertStringContainsString('__compiler_http_build_query_llvm', $owner);
        $this->assertStringContainsString('#33711', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/HttpBuildQueryJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitHttpBuildQuery.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/HttpBuildQueryArrayLlvm.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHttpBuildQuery.php');
        $this->assertStringContainsString('#33208', $jit);
        $this->assertStringContainsString('#33711', $jit);
        $this->assertStringContainsString('StringHttpBuildQuery::ensureLinked', $jit);
        $this->assertStringContainsString('__compiler_http_build_query_llvm', $jit);
    }

  public function testHttpBuildQueryLazyLinkedAtCallSiteNotEagerInStringImplement(): void
    {
        $string = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $pos = strpos($string, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($string, 'private function implementStrlen', $pos);
        $this->assertNotFalse($next);
        $body = substr($string, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'StringHttpBuildQuery::implement',
            $body,
            'Type\\String_::implement must not eagerly link http_build_query (#35613)'
        );

        $callSite = (string) file_get_contents(__DIR__.'/../../ext/standard/http_build_query.php');
        $this->assertStringContainsString('StringHttpBuildQuery::ensureLinked', $callSite);
    }

    public function testNoNewRuntimeCForHttpBuildQueryAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/http_build_query.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/http_build_query.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_http_build_query.c');
    }
}
