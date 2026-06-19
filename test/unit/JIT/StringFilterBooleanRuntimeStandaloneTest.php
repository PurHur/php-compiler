<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFilterBoolean;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9858: AOT standalone filter boolean parse must use FilterBooleanJitHelper PHP, not LLVM parser.
 *
 * @group aot-lint
 */
final class StringFilterBooleanRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesFilterParseBooleanForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFilterBoolean::implement($ctx);

        $fn = $ctx->lookupFunction('__compiler_filter_parse_boolean_string');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringFilterBooleanRoutesThroughFilterBooleanJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringFilterBoolean.php');
        $this->assertStringContainsString('FilterBooleanJitHelper', $source);
        $this->assertStringNotContainsString('emitLengthCascade', $source);
        $this->assertStringNotContainsString('bytesMatchLiteral', $source);
    }
}
