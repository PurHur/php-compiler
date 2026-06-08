<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream read/seek/lock LLVM helpers must lower without C symbols in phpc_stream.c (#5343 phase 4).
 *
 * @group aot-lint
 */
final class StreamReadRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamReadHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamRead::ensureLinked($ctx);

        foreach ([
            '__compiler_flock',
            '__compiler_fpassthru',
            '__compiler_ftruncate',
            '__compiler_ftell',
            '__compiler_fgetc',
            '__compiler_fgets',
            '__compiler_stream_get_line',
            '__compiler_fseek',
            '__compiler_stream_get_contents',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testPhpcStreamCNoLongerDefinesReadHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_stream.c');
        $this->assertStringNotContainsString('__compiler_flock(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_fpassthru(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_ftruncate(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_ftell(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_fgetc(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_fgets(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_stream_get_line(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_fseek(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_stream_get_contents(int64_t', $source);
        $this->assertStringContainsString('StreamReadJit.php', $source);
    }
}
