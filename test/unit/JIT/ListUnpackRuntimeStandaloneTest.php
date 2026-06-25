<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ListUnpackRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #11437: ListUnpack bridge CFG must verify in standalone AOT modules.
 *
 * @group aot-lint
 */
final class ListUnpackRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesListUnpackBridgesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ListUnpackRuntime::ensureLinked($ctx);

        foreach (
            [
                '__list_unpack__valueBoxIsArray',
                '__list_unpack__valueBoxIsString',
                '__list_unpack__valueBoxIsListDestructUnpackable',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $message = '';
        $ctx->module->verify($ctx->module::VERIFY_ACTION_RETURN, $message);
        $this->assertStringNotContainsString('list_unpack_array_check', $message);
        $this->assertStringNotContainsString('list_unpack_value_box_is_unpackable_entry', $message);
        $this->assertStringNotContainsString('parentless instruction', strtolower($message));
    }
}
