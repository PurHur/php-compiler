<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamGlobals;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Stream handle-table globals + __phpc_resolve_stream must LLVM-lower without phpc_stream.c (#5343 phase 5).
 *
 * @group aot-lint
 */
final class StreamGlobalsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesResolveStreamAndGlobalsForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamGlobals::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__phpc_resolve_stream');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        foreach ([
            StreamGlobalsJit::GLOBAL_HANDLES,
            StreamGlobalsJit::GLOBAL_PATHS,
            StreamGlobalsJit::GLOBAL_WAS_USED,
            StreamGlobalsJit::GLOBAL_CHUNK_SIZE,
            StreamGlobalsJit::GLOBAL_WRITE_BUFFER,
            StreamGlobalsJit::GLOBAL_READ_BUFFER,
        ] as $name) {
            $this->assertNotNull($ctx->module->getNamedGlobal($name), $name);
        }
        $this->assertNull(
            $ctx->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_WRITE_BUFFER_STORAGE),
            'write buffer byte storage must not bloat .bss — allocate on first fwrite (#36195)'
        );
    }

    public function testPhpcStreamCDeletedFromRuntime(): void
    {
        $path = __DIR__.'/../../../lib/AOT/runtime/phpc_stream.c';
        $this->assertFileDoesNotExist($path);
    }

    public function testLinkerNoLongerListsPhpcStreamC(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_stream.c', $source);
    }
}
