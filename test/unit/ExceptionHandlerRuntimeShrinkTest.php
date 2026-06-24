<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\ExceptionHandlerOutput;
use PHPUnit\Framework\TestCase;

/** ExceptionHandlerJitRuntime must route through ExceptionHandlerJitHelper PHP, not LLVM globals (#9473). */
final class ExceptionHandlerRuntimeShrinkTest extends TestCase
{
    public function testExceptionHandlerJitRuntimeUsesHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExceptionHandlerJitRuntime.php');
        $this->assertStringContainsString('ExceptionHandlerJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("GLOBAL_DEPTH = 'phpc_exception_handler_depth'", $source);
        $this->assertStringNotContainsString("GLOBAL_FN = 'phpc_exception_handler_fn'", $source);
        $this->assertStringNotContainsString('ensureGlobals', $source);
        $this->assertStringNotContainsString('implementSetApply(', $source);
        $this->assertStringNotContainsString('malloc', $source);
    }

    public function testStandaloneModuleHasNoExceptionHandlerLlvmGlobals(): void
    {
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $ctx = new \PHPCompiler\JIT\Context($runtime, \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE);
        ExceptionHandlerOutput::registerExternals($ctx);
        $this->assertNull($ctx->module->getNamedGlobal('phpc_exception_handler_depth'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_exception_handler_fn'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_exception_handler_name'));
    }

    public function testExceptionHandlerJitHelperSemantics(): void
    {
        $helper = \PHPCompiler\ext\standard\ExceptionHandlerJitHelper::class;
        $this->assertNull($helper::setApply(100, 'a'));
        $this->assertSame('a', $helper::setApply(200, 'b'));
        $this->assertSame(2, $helper::currentDepth());
        $this->assertSame(200, $helper::handlerFnAddrAt(1));
        $this->assertTrue($helper::restoreApply());
        $this->assertSame(1, $helper::currentDepth());
        $this->assertSame('a', $helper::setApply(0, null));
        $this->assertSame(0, $helper::currentDepth());
    }
}
