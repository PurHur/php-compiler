<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringJsonEncode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6852: json_encode LLVM helpers must lower without __compiler_json_encode_hashtable.
 *
 * @group aot-lint
 */
final class StringJsonEncodeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesJsonEncodeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringJsonEncode::ensureLinked($ctx);

        foreach (['__compiler_json_encode_value', '__compiler_json_encode_array'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
        $this->assertNull($ctx->module->getNamedFunction('__compiler_json_encode_hashtable'));
    }
}
