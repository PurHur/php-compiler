<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\ErrorHandlerOutput;
use PHPUnit\Framework\TestCase;

/** ErrorHandlerJitRuntime must route through ErrorHandlerJitHelper PHP, not LLVM globals (#9472). */
final class ErrorHandlerRuntimeShrinkTest extends TestCase
{
    public function testErrorHandlerJitRuntimeUsesHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ErrorHandlerJitRuntime.php');
        $this->assertStringContainsString('ErrorHandlerJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("GLOBAL_DEPTH = 'phpc_error_handler_depth'", $source);
        $this->assertStringNotContainsString("GLOBAL_FN = 'phpc_error_handler_fn'", $source);
        $this->assertStringNotContainsString('ensureGlobals', $source);
        $this->assertStringNotContainsString('implementSetApply(', $source);
        $this->assertStringNotContainsString('malloc', $source);
    }

    public function testStandaloneModuleHasNoErrorHandlerLlvmGlobals(): void
    {
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $ctx = new \PHPCompiler\JIT\Context($runtime, \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE);
        ErrorHandlerOutput::registerExternals($ctx);
        $this->assertNull($ctx->module->getNamedGlobal('phpc_error_handler_depth'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_error_handler_fn'));
    }

    public function testErrorHandlerJitHelperExists(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/ErrorHandlerJitHelper.php');
    }
}
