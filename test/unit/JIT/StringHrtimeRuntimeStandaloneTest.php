<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5634: AOT standalone must define hrtime helpers without phpc_hrtime.c.
 * Issue #9018 / #9182: JIT hrtime routes through HrtimeJitHelper + VmHrtimeNative PHP.
 *
 * @group aot-lint
 */
final class StringHrtimeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesHrtimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringHrtime::ensureLinked($ctx);

        foreach (['__compiler_hrtime_ns', '__compiler_hrtime_pair'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testStringHrtimeRoutesThroughHrtimeJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHrtime.php');
        $this->assertStringContainsString('StringHrtimeRuntime::ensureLinked', $source);
        $this->assertDoesNotMatchRegularExpression("/lookupFunction\\(\\s*'clock_gettime'\\s*\\)/", $source);
        $this->assertStringNotContainsString('__phpc_hrtime_monotonic_read', $source);

        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringHrtimeRuntime.php');
        $this->assertStringContainsString('HrtimeJitHelper', $runtimeSource);

        $helperSource = (string) \file_get_contents(__DIR__.'/../../../ext/standard/HrtimeJitHelper.php');
        $this->assertStringContainsString('VmHrtimeNative::readMonotonic', $helperSource);

        $nativeSource = (string) \file_get_contents(__DIR__.'/../../../ext/standard/VmHrtimeNative.php');
        $this->assertStringContainsString('clock_gettime', $nativeSource);
    }
}
