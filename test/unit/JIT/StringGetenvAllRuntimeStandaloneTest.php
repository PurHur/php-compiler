<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetenvAll;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5075 phase 2: AOT standalone must define __compiler_getenv_all for zero-arg getenv().
 *
 * @group aot-lint
 */
final class StringGetenvAllRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGetenvAllForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGetenvAll::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_getenv_all');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
