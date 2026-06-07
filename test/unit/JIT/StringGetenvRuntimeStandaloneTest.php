<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5330: AOT standalone must define __compiler_getenv without superglobals_refresh.c C body.
 *
 * @group aot-lint
 */
final class StringGetenvRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesDefinesGetenvForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGetenv::ensureStandaloneBodies($ctx);
        $fn = $ctx->lookupFunction('__compiler_getenv');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());

        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
        $this->assertStringNotContainsString('void __compiler_getenv(', $source);
    }
}
