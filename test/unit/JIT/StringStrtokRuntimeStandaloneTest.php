<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStrtokJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5232 / #6111: AOT standalone must define phpc_strtok without phpc_strtok.c.
 *
 * @group aot-lint
 */
final class StringStrtokRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesStrtokForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringStrtokJit::implement($ctx);

        foreach (['phpc_strtok', '__phpc_strtok_reset', '__phpc_strtok_init'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
