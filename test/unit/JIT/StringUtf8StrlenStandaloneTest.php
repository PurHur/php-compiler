<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringUtf8StrlenJit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #158: AOT standalone must define __compiler_utf8_strlen without superglobals_refresh.c.
 *
 * @group aot-lint
 */
final class StringUtf8StrlenStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUtf8StrlenFromSuperglobalsC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
    }

    public function testImplementDefinesUtf8StrlenForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringUtf8StrlenJit::implement($ctx);

        $fn = $ctx->lookupFunction('__compiler_utf8_strlen');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
