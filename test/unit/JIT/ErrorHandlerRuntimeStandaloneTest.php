<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorHandlerOutput;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5316: AOT standalone must define error-handler helpers without phpc_error_handler.c.
 *
 * @group aot-lint
 */
final class ErrorHandlerRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesErrorHandlerStackForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ErrorHandlerOutput::registerExternals($ctx);

        foreach (
            [
                '__phpc_error_handler_dispatch',
                '__phpc_error_handler_restore_apply',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
        foreach (['__phpc_error_handler_set_apply', '__phpc_error_handler_get_apply'] as $name) {
            $this->assertNotNull($ctx->lookupFunction($name));
        }
    }
}
