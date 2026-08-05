<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStrtok;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9812 / #27645: AOT standalone strtok defines phpc_strtok via LLVM globals.
 *
 * @group aot-lint
 */
final class StringStrtokRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesStrtokForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringStrtok::implement($ctx);

        foreach (['phpc_strtok', '__phpc_strtok_reset', '__phpc_strtok_init'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testStringStrtokRoutesThroughLlvmEmitter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringStrtok.php');
        $this->assertStringContainsString('StringStrtokJit', $source);
        $jit = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringStrtokJit.php');
        $this->assertStringContainsString('__phpc_strtok_buf', $jit);
    }
}
