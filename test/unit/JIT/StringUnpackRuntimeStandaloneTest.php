<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\UnpackJitRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6306 / #13063: unpack() routes through UnpackJitHelper PHP, not StringUnpackJit LLVM.
 *
 * @group aot-lint
 */
final class StringUnpackRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesUnpackThroughPhpHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/unpack_jit_runtime.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringUnpackJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_unpack.c');
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/UnpackJitRuntime.php');
        $this->assertStringContainsString('StringUnpack', $runtime);
        $this->assertStringNotContainsString('unpack_jit_runtime.c', $runtime);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnpack.php');
        $this->assertStringContainsString('UnpackJitHelper', $bridge);
        $this->assertStringNotContainsString('StringUnpackJit', $bridge);
        $aotLinker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_unpack.c', $aotLinker);
        $engine = (string) file_get_contents(__DIR__.'/../../../ext/standard/UnpackEngine.php');
        $this->assertStringContainsString('UnpackEngine', $engine);
        $vmPack = (string) file_get_contents(__DIR__.'/../../../ext/standard/VmPack.php');
        $this->assertStringContainsString('UnpackEngine::unpack', $vmPack);
        $this->assertStringNotContainsString('\\unpack(', $vmPack);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesUnpackForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        UnpackJitRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_unpack');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
