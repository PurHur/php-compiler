<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamBuffer;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream_set_* buffer LLVM helpers must lower without C symbols in phpc_stream.c (#5343 phase 4).
 *
 * @group aot-lint
 */
final class StreamBufferRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamBufferHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamBuffer::ensureLinked($ctx);

        foreach ([
            '__compiler_stream_set_chunk_size',
            '__compiler_stream_set_timeout',
            '__compiler_stream_set_write_buffer',
            '__compiler_stream_set_read_buffer',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
            $this->assertFalse(
                Builtin\StreamIoRuntime::isDeferStub($fn),
                $name.' must not remain an inventory defer stub (#30788)'
            );
        }
    }
}
