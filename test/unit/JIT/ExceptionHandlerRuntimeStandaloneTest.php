<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ExceptionHandlerOutput;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4311: AOT standalone must define exception-handler helpers in PHP LLVM lowering.
 *
 * @group aot-lint
 */
final class ExceptionHandlerRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesExceptionHandlerStackForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ExceptionHandlerOutput::registerExternals($ctx);

        foreach (
            [
                '__phpc_exception_handler_dispatch',
                '__phpc_exception_handler_set_apply',
                '__phpc_exception_handler_restore_apply',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
