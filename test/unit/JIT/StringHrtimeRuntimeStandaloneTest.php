<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5634: AOT standalone must define hrtime helpers without phpc_hrtime.c.
 * Issue #9018 / #9182: JIT hrtime routes through HrtimeJitHelper + VmHrtimeNative PHP.
 * Issue #26910: __compiler_hrtime_ns return ABI must match writeLong (i64) vs writeDouble.
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

    /** Type.php declaration must agree with JitDate boxing (#26910). */
    public function testHrtimeNsDeclarationMatchesPlatformAbi(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $fn = $ctx->lookupFunction('__compiler_hrtime_ns');
        $this->assertNotNull($fn);
        $retTy = $fn->typeOf();
        // Function values type as pointer-to-function under LLVM-C.
        if (\method_exists($retTy, 'getElementType')) {
            $retTy = $retTy->getElementType();
        }
        $this->assertTrue(\method_exists($retTy, 'getReturnType'));
        $printed = $retTy->getReturnType()->toString();
        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $this->assertStringContainsString('double', $printed);
        } else {
            $this->assertMatchesRegularExpression('/i64|int64/', $printed);
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
