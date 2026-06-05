<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6110: AOT standalone must define microtime helpers without phpc_microtime.c.
 *
 * @group aot-lint
 */
final class StringMicrotimeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesMicrotimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringMicrotime::ensureLinked($ctx);

        foreach (['__compiler_microtime_string', '__compiler_microtime_float'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
