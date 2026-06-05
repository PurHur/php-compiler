<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGettimeofday;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6110: AOT standalone must define gettimeofday helpers without phpc_gettimeofday.c.
 *
 * @group aot-lint
 */
final class StringGettimeofdayRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGettimeofdayForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringGettimeofday::ensureLinked($ctx);

        foreach (['__compiler_gettimeofday_array', '__compiler_gettimeofday_float'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
