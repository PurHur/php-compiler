<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\ExceptionHandlerJitRuntime;
use PHPCompiler\JIT\Builtin\ExceptionHandlerOutput;
use PHPUnit\Framework\TestCase;

/**
 * ExceptionHandlerJitRuntime: honest module-globals stack (ErrorHandler #17671 shape).
 * No dishonest thin ABI fork (#21325). NestedJIT helper deferred (string static slots).
 */
final class ExceptionHandlerRuntimeShrinkTest extends TestCase
{
    public function testNoStandaloneThinAbiFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExceptionHandlerJitRuntime.php');
        $this->assertStringNotContainsString('implementStandaloneThinAbi', $source);
        $this->assertStringNotContainsString('implementStandaloneThinAbiReuseBuilder', $source);
        $this->assertStringNotContainsString('standaloneAbiFunction', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringContainsString('phpc_xh_stack_depth', $source);
        $this->assertStringContainsString('ensureStackGlobals', $source);
        $this->assertStringContainsString('implementDispatchBridge', $source);
        $this->assertStringContainsString('implementSetApplyBridge', $source);
        $this->assertStringContainsString('implementGetApplyBridge', $source);
    }

    public function testStandaloneFullStackDefinesExceptionHandlerGlobals(): void
    {
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $ctx = new \PHPCompiler\JIT\Context($runtime, \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE);
        ExceptionHandlerOutput::registerExternals($ctx);
        ExceptionHandlerJitRuntime::ensureLinked($ctx);
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_xh_stack_depth'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_xh_stack_top_fn'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_xh_stack_top_name'));
    }

    public function testExceptionHandlerJitHelperSemantics(): void
    {
        $helper = \PHPCompiler\ext\standard\ExceptionHandlerJitHelper::class;
        $this->assertSame('', $helper::setApply(100, 'a'));
        $this->assertSame('a', $helper::setApply(200, 'b'));
        $this->assertSame(2, $helper::currentDepth());
        $this->assertSame(200, $helper::handlerFnAddrAt(1));
        $this->assertTrue($helper::restoreApply());
        $this->assertSame(1, $helper::currentDepth());
        $this->assertSame('a', $helper::setApply(0, ''));
        $this->assertSame(0, $helper::currentDepth());
        $this->assertSame('', $helper::getCurrentName());
    }
}
