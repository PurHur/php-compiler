<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::compileToFile always-on HttpResponseRuntime::ensureStandaloneBodies
 * (#35803 / peer #35443 ErrorBridge / #33965 Type::initialize lazy).
 *
 * Full standalone must not NestedJIT http_response_code bridges during compileToFile
 * prologue when unused — leftover Context NestedJIT vs Runtime ABI drift mints
 * http_response_code_apply.1 (#31894 / #32122).
 */
final class ContextCompileToFileLazyHttpResponseRuntimeShrinkTest extends TestCase
{
    public function testCompileToFileDropsEagerHttpResponseEnsure(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35803', $context);
        $pos = strpos($context, 'public function compileToFile');
        $this->assertNotFalse($pos);
        $end = strpos($context, 'Progress::noteFunction(\'jit_context_compile_common_begin\')', $pos);
        $this->assertNotFalse($end);
        $body = substr($context, $pos, $end - $pos);

        $this->assertStringNotContainsString(
            'HttpResponseRuntime::ensureStandaloneBodies($this)',
            $body,
            'compileToFile must not eagerly HttpResponseRuntime::ensureStandaloneBodies (#35803)'
        );
        $this->assertStringContainsString(
            'HttpResponseCode::emitResetForStandaloneMain($this)',
            $body,
            'full {main} still resets http_response_code via emitReset (#35803)'
        );
    }

    public function testEmitResetSelfEnsures(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $pos = strpos($source, 'public static function emitResetForStandaloneMain');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function emitStandaloneStatusLine', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringContainsString(
            'self::ensureLinked($context)',
            $body,
            'emitResetForStandaloneMain must ensureLinked before lookup (#35803)'
        );
    }

    public function testCallSitesStillEnsureHttpResponse(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString(
            'HttpResponseRuntime::ensureLinked',
            $source,
            'PendingHeaders must ensure HttpResponse lazily (#35803)'
        );
    }

    public function testNoNewRuntimeC(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertDirectoryExists($runtimeDir);
        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        $this->assertSame([], $cFiles, 'lib/AOT/runtime must stay empty of *.c (#35803)');
    }
}
