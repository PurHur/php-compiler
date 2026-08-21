<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __phpc_stream_path ABI shell from Builtin\Type (#33258).
 *
 * NestedJIT/AOT bridge stays StreamPathRuntime (php-src ext/standard/streamsfuncs.c).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint stream_path.1 (#31894 / #32122).
 */
final class TypeDeadStreamPathAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnStreamPathAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33258', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__phpc_stream_path[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __phpc_stream_path (#33258)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__phpc_stream_path'",
            $type,
            'Builtin\\Type must not always-register __phpc_stream_path (#33258)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString('StreamPathRuntime::declareStreamPathAbi', $type);
        // Next leftover sentinel (session_start_apply still Type always-on; #33258 stream_path dropped).
        $this->assertStringContainsString("registerFunction('__phpc_session_start_apply'", $type);
    }

    public function testRuntimeOwnerDeclaresStreamPathAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamPathRuntime.php');
        $this->assertStringContainsString('#33258', $owner);
        $this->assertStringContainsString('declareStreamPathAbi', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('__phpc_stream_path', $owner);
    }

    public function testNoNewRuntimeCForStreamPathAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/stream_path.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/stream_path.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_stream_path.c');
    }
}
