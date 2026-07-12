<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\ErrorHandlerJitRuntime;
use PHPCompiler\JIT\Builtin\ErrorHandlerOutput;
use PHPUnit\Framework\TestCase;

/** Error-handler stack uses module globals on standalone AOT (#17671); VM SSOT stays ErrorHandlerJitHelper. */
final class ErrorHandlerRuntimeShrinkTest extends TestCase
{
    public function testErrorHandlerJitRuntimeUsesModuleStackGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ErrorHandlerJitRuntime.php');
        $this->assertStringContainsString('phpc_eh_stack_depth', $source);
        $this->assertStringContainsString('ensureStackGlobals', $source);
        $this->assertStringNotContainsString("GLOBAL_DEPTH = 'phpc_error_handler_depth'", $source);
        $this->assertStringNotContainsString('malloc', $source);
    }

    public function testStandaloneFullStackDefinesEhStackGlobals(): void
    {
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $ctx = new \PHPCompiler\JIT\Context($runtime, \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE);
        ErrorHandlerOutput::registerExternals($ctx);
        ErrorHandlerJitRuntime::ensureLinked($ctx, true);
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_eh_stack_depth'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_eh_stack_top_name'));
    }

    public function testErrorHandlerJitHelperExists(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/ErrorHandlerJitHelper.php');
    }
}
