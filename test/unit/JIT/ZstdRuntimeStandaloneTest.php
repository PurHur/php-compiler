<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringZstd;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT keeps StringZstdJit until ZstdJitHelper standalone link is reliable (#8869).
 *
 * @group aot-lint
 */
final class ZstdRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesZstdHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringZstd::ensureLinked($ctx);

        foreach (['__compiler_zstd_compress', '__compiler_zstd_decompress'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
