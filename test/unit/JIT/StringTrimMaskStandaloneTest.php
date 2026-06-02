<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringTrimMask;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4646: AOT standalone must declare __phpc_char_in_mask for linker resolution.
 *
 * @group aot-lint
 */
final class StringTrimMaskStandaloneTest extends TestCase
{
    public function testEnsureLinkedDeclaresCharInMaskForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringTrimMask::ensureLinked($ctx);
        $this->assertNotNull($ctx->lookupFunction('__phpc_char_in_mask'));
    }
}
