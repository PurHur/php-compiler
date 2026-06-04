<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5634: AOT standalone must define hrtime helpers without phpc_hrtime.c.
 *
 * @group aot-lint
 */
final class StringHrtimeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesHrtimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringHrtime::ensureLinked($ctx);

        foreach (['__compiler_hrtime_ns', '__compiler_hrtime_pair'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
