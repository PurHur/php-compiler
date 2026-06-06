<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\UnpackJitRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6306: unpack() LLVM helpers replace unpack_jit_runtime.c.
 *
 * @group aot-lint
 */
final class StringUnpackRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesUnpackForStandalone(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/unpack_jit_runtime.c');

        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        UnpackJitRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_unpack');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnpackJit.php');
        $this->assertStringContainsString('__compiler_unpack', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/UnpackJitRuntime.php');
        $this->assertStringContainsString('StringUnpackJit', $linker);
        $this->assertStringNotContainsString('unpack_jit_runtime.c', $linker);
    }
}
