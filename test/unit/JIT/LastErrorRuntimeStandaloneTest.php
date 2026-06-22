<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\LastErrorRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5534 / #9607: AOT standalone last-error ABI via ErrorLastJitHelper PHP, not LLVM globals.
 *
 * @group aot-lint
 */
final class LastErrorRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesLastErrorForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        LastErrorRuntime::ensureLinked($ctx);

        foreach (
            [
                '__phpc_last_error_record',
                '__phpc_last_error_clear',
                '__phpc_last_error_is_active',
                '__phpc_last_error_to_hashtable',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }

        $this->assertNotNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\ErrorLastJitHelper::isActive')] ?? null
        );
        $this->assertNull($ctx->module->getNamedGlobal('phpc_last_error_active'));
    }
}
