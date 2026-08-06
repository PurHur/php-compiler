<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObOutputJitHelper;
use PHPUnit\Framework\TestCase;

/** ObOutputRuntime: JitVmHelperLink + ObOutputJitHelper (#9268, #12951, #19422, #20169, #20443, #21066, #21469, #22049). */
final class ObOutputRuntimeShrinkTest extends TestCase
{
    public function testObOutputRuntimeUsesHelperNotLlvmStack(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputRuntime.php');
        $this->assertStringContainsString('ObOutputJitBridge::implement', $runtime);
        $this->assertStringNotContainsString('JitObOutputKernel', $runtime);
        $this->assertStringNotContainsString('ObOutputStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('ObOutputUserScriptLlvm', $runtime);
        $runtimeLines = \substr_count($runtime, "\n") + 1;
        $this->assertLessThan(60, $runtimeLines);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitObOutputKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitObWriteStdoutKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_ob_write_stdout_kernel.php');

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('ObOutputJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('ensureEchoAbiDeclared', $bridge);
        $this->assertStringContainsString('ObOutputEchoJitEmit::implementAll', $bridge);
        $this->assertStringContainsString('ObStorageLlvm::implement', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('JitObOutputKernel', $bridge);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $bridge);
        $this->assertStringNotContainsString('implementMissingUserScriptAbiPads', $bridge);
        $this->assertStringNotContainsString('finishUserScriptEmit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('ObOutputUserScriptLlvm', $bridge);
        $this->assertStringNotContainsString('ObOutputStandaloneLlvm', $bridge);
        $this->assertStringNotContainsString('implementPopBuffer', $bridge);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/ObStorageLlvm.php');
        $storage = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStorageLlvm.php');
        $this->assertStringContainsString('ObStorageGlobals::ensureGlobals', $storage);
        $this->assertStringContainsString('GLOBAL_STORAGE', $storage);
        $this->assertStringContainsString('HANDLER_URL_REWRITER', $storage);
        $this->assertStringContainsString('memcpy', $storage);
        $bridgeLines = \substr_count($bridge, "\n") + 1;
        $this->assertLessThan(820, $bridgeLines, 'ObOutputJitBridge LOC (#12999 echo ABI forward declare)');
        $this->assertDoesNotMatchRegularExpression(
            '/as\s*\$[a-zA-Z_]+\s*=>\s*\[\$[a-zA-Z_]+,\s*\$[a-zA-Z_]+,\s*\.\.\.\$/',
            $bridge
        );
        $execCaptureRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $execCaptureRuntime);
        $this->assertStringContainsString('ObOutputExecCaptureJitHelper', $execCaptureRuntime);
        $this->assertStringContainsString('implementGetContents', $execCaptureRuntime);
        $this->assertStringContainsString('ensureReadApiLinked', $execCaptureRuntime);
        $this->assertStringNotContainsString('JitObOutputExecCaptureKernel', $execCaptureRuntime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $execCaptureRuntime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $execCaptureRuntime);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $execCaptureRuntime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitObOutputExecCaptureKernel.php');

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ObOutputJitHelper.php');
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel', $helper);
        $this->assertStringNotContainsString('echo $chunk', $helper);
        $this->assertStringNotContainsString('[] === self::$stack', $helper);
        $this->assertStringNotContainsString('[] !== self::$stack', $helper);
        $this->assertStringNotContainsString('use PHPCompiler\VM\ObStackLimits', $helper);
        $this->assertStringNotContainsString('ObStackLimits::BUF_SIZE', $helper);

        $execHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ObOutputExecCaptureJitHelper.php');
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel', $execHelper);
        $this->assertStringNotContainsString('echo $chunk', $execHelper);

        $writeKernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitObWriteStdoutKernel.php');
        $this->assertStringContainsString('lookupFunction(\'write\')', $writeKernel);
    }

    public function testSpineBundleIncludesObOutputWriteStdoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitObWriteStdoutKernel.php', $spine);
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel.php', $spine);
        $this->assertStringNotContainsString('JitObOutputExecCaptureKernel.php', $spine);
        $this->assertStringContainsString('ObOutputJitHelper.php', $spine);
        $this->assertStringContainsString('ObOutputExecCaptureJitHelper.php', $spine);
        $this->assertStringNotContainsString('JitObOutputKernel.php', $spine);
        $this->assertStringNotContainsString('ObOutputUserScriptLlvm.php', $spine);
        $this->assertStringNotContainsString('ObOutputExecCaptureLlvm.php', $spine);
    }

    public function testObOutputJitHelperStackSemantics(): void
    {
        ObOutputJitHelper::reset();
        $this->assertSame(0, ObOutputJitHelper::getLevel());
        ObOutputJitHelper::start();
        ObOutputJitHelper::appendString('hello');
        $this->assertSame(1, ObOutputJitHelper::getLevel());
        $this->assertSame('hello', ObOutputJitHelper::getContents());
        $this->assertSame(5, ObOutputJitHelper::getLength());
        $this->assertSame(1, ObOutputJitHelper::endClean());
        $this->assertSame(0, ObOutputJitHelper::getLevel());
    }

    public function testObOutputJitHelperNestedBuffers(): void
    {
        ObOutputJitHelper::reset();
        ObOutputJitHelper::start();
        ObOutputJitHelper::start();
        ObOutputJitHelper::appendString('x');
        $this->assertSame(2, ObOutputJitHelper::getLevel());
        $this->assertSame(1, ObOutputJitHelper::endFlush());
        $this->assertSame(1, ObOutputJitHelper::getLevel());
        $this->assertSame('x', ObOutputJitHelper::getContents());
    }

    public function testObOutputExecCaptureJitHelperReadApi(): void
    {
        \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::reset();
        \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::start();
        \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::appendString('ab');
        $this->assertSame(1, \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::getLevel());
        $this->assertSame('ab', \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::getContents());
        $this->assertSame(2, \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::getLength());
        $this->assertSame(1, \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::endClean());
        $this->assertSame(0, \PHPCompiler\ext\standard\ObOutputExecCaptureJitHelper::getLevel());
    }
}
