<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringTrimMask;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4646 / #14908: AOT standalone must define __phpc_char_in_mask via CharInMaskJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringTrimMaskStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesCharInMaskForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringTrimMask::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__phpc_char_in_mask');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
