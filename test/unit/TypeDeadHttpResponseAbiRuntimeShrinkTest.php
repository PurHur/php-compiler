<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on HttpResponseCode::implement (#33965 / peer #33945).
 *
 * NestedJIT/AOT bridge stays HttpResponseRuntime / JitHttpResponseCode
 * (php-src ext/standard/head.c). Call-site ensureLinked + non-thin
 * Context::ensureStandaloneBodies before emitReset own the ABI so leftover
 * Type always-on NestedJIT cannot mint http_response_code_apply.1
 * (#31894 / #32122). emitReset must not re-enter ensureLinked (#11206).
 */
final class TypeDeadHttpResponseAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerHttpResponseImplement(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33965', $type);
        $this->assertStringNotContainsString(
            'HttpResponseCode::implement($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly implement HttpResponse (#33965)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_http_response_code_apply[\'"]/',
            $type,
            'Builtin\\Type must not always-declare http_response_code ABI (#33965)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__phpc_http_response_code_apply'",
            $type,
            'Builtin\\Type must not always-register http_response_code ABI (#33965)'
        );
    }

    public function testCallSiteEnsureLinksBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHttpResponseCode.php');
        $this->assertStringContainsString('#33965', $jit);
        $this->assertStringContainsString('HttpResponseRuntime::ensureLinked($context)', $jit);
        $posLink = strpos($jit, 'HttpResponseRuntime::ensureLinked($context)');
        $posLookup = strpos($jit, "lookupFunction('__phpc_http_response_code_apply')");
        $this->assertNotFalse($posLink);
        $this->assertNotFalse($posLookup);
        $this->assertLessThan(
            $posLookup,
            $posLink,
            'JitHttpResponseCode::invoke must ensureLinked before lookup (#33965)'
        );
    }

    public function testContextLinksHttpResponseBeforeNonThinEmitReset(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#33965', $ctx);
        $this->assertStringContainsString(
            'HttpResponseRuntime::ensureStandaloneBodies($this)',
            $ctx
        );
        $posBodies = strpos($ctx, 'HttpResponseRuntime::ensureStandaloneBodies($this)');
        $posEmit = strpos($ctx, 'HttpResponseCode::emitResetForStandaloneMain');
        $this->assertNotFalse($posBodies);
        $this->assertNotFalse($posEmit);
        $this->assertLessThan(
            $posEmit,
            $posBodies,
            'Context must ensureStandaloneBodies before emitReset (#33965/#11206)'
        );
    }

    public function testEmitResetDoesNotRelink(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $this->assertStringContainsString('#33965', $owner);
        $this->assertStringContainsString('#11206', $owner);
        $this->assertStringNotContainsString(
            "emitResetForStandaloneMain(Context \$context): void\n    {\n        if (Builtin::LOAD_TYPE_STANDALONE !== \$context->loadType) {\n            return;\n        }\n        self::ensureLinked(\$context);",
            $owner
        );
    }

    public function testNoNewRuntimeCForHttpResponseAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/http_response_code.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/http_response_code.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_http_response.c');
    }
}
