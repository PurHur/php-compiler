<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamIo;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * fopen/fread/fwrite/tmpfile LLVM helpers must lower without C symbols in phpc_stream.c (#5343 phase 3).
 *
 * @group aot-lint
 */
final class StreamIoRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamIoHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamIo::ensureLinked($ctx);

        foreach ([
            '__compiler_fwrite',
            '__compiler_fopen',
            '__compiler_tmpfile',
            '__compiler_fread',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testPhpcStreamCNoLongerDefinesIoHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/phpc_stream.c');
        $this->assertStringNotContainsString('__compiler_fwrite(int64_t', $source);
        $this->assertStringNotContainsString('__compiler_fopen(__string__', $source);
        $this->assertStringNotContainsString('__compiler_tmpfile(void)', $source);
        $this->assertStringNotContainsString('__compiler_fread(int64_t', $source);
        $this->assertStringContainsString('StreamIoJit.php', $source);
    }
}
