<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringBz2;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * bz2 LLVM helpers must lower for standalone AOT without StringBz2Jit (#8868).
 *
 * @group aot-lint
 */
final class Bz2RuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesBz2HelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringBz2::ensureLinked($ctx);

        foreach (['__compiler_bzcompress', '__compiler_bzdecompress'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
