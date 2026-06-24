<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6306: unpack() LLVM helpers replace unpack_jit_runtime.c.
 *
 * @group aot-lint
 */
final class StringUnpackRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUnpackJitC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/unpack_jit_runtime.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_unpack.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnpackJit.php');
        $this->assertStringContainsString('__compiler_unpack', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/UnpackJitRuntime.php');
        $this->assertStringContainsString('StringUnpack', $linker);
        $this->assertStringNotContainsString('unpack_jit_runtime.c', $linker);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnpack.php');
        $this->assertStringContainsString('UnpackJitHelper', $bridge);
        $aotLinker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_unpack.c', $aotLinker);
        $engine = (string) file_get_contents(__DIR__.'/../../../ext/standard/UnpackEngine.php');
        $this->assertStringContainsString('UnpackEngine', $engine);
        $vmPack = (string) file_get_contents(__DIR__.'/../../../ext/standard/VmPack.php');
        $this->assertStringContainsString('UnpackEngine::unpack', $vmPack);
        $this->assertStringNotContainsString('\\unpack(', $vmPack);
    }

    /**
     * Lower unpack helper chain through need_bytes (emitParseFormat is LLVM-heavy; full
     * standalone link tracked with AOT smoke in UnpackBuiltinTest @group llvm).
     *
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesUnpackHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        $ref = new \ReflectionClass(\PHPCompiler\JIT\Builtin\StringUnpackJit::class);
        $implementIfMissing = $ref->getMethod('implementIfMissing');
        $implementIfMissing->setAccessible(true);
        $ensureLibc = $ref->getMethod('ensureLibc');
        $ensureLibc->setAccessible(true);
        $ensureRuntimeHelpers = $ref->getMethod('ensureRuntimeHelpers');
        $ensureRuntimeHelpers->setAccessible(true);
        $ensureLibc->invoke(null, $ctx);
        $ensureRuntimeHelpers->invoke(null, $ctx);

        $steps = [
            '__compiler_unpack_fail' => $ref->getMethod('emitFail'),
            '__compiler_unpack_read_long' => $ref->getMethod('emitReadLong'),
            '__compiler_unpack_need_bytes' => $ref->getMethod('emitNeedBytes'),
        ];
        foreach ($steps as $name => $method) {
            $method->setAccessible(true);
            $implementIfMissing->invoke(null, $ctx, $name, $method->getClosure());
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
