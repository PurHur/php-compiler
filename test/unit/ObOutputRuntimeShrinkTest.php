<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObOutputJitHelper;
use PHPUnit\Framework\TestCase;

/** ObOutputRuntime: always NestedJIT ObOutputJitHelper (#9268, #12951, #19422, #20169, #20443, #21066, #21469). */
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
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringContainsString('ObOutputEchoJitEmit::implementAll', $bridge);
        $this->assertStringNotContainsString('JitObOutputKernel', $bridge);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $bridge);
        $this->assertStringNotContainsString('implementMissingUserScriptAbiPads', $bridge);
        $this->assertStringNotContainsString('finishUserScriptEmit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('ObOutputUserScriptLlvm', $bridge);
        $this->assertStringNotContainsString('ObStorageGlobals::ensureGlobals', $bridge);
        $this->assertStringNotContainsString('GLOBAL_STORAGE', $bridge);
        $this->assertStringNotContainsString('implementPopBuffer', $bridge);
        $this->assertStringNotContainsString('ObOutputStandaloneLlvm', $bridge);
        $this->assertStringContainsString('ensureEchoAbiDeclared', $bridge);
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
        $this->assertStringContainsString('JitObOutputExecCaptureKernel::ensureLinked', $execCaptureRuntime);
        $this->assertStringContainsString('isThinStandaloneAotMain', $execCaptureRuntime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $execCaptureRuntime);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $execCaptureRuntime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ObOutputExecCaptureLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitObOutputExecCaptureKernel.php');

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ObOutputJitHelper.php');
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel', $helper);
        $this->assertStringNotContainsString('echo $chunk', $helper);
        $this->assertStringNotContainsString('[] === self::$stack', $helper);
        $this->assertStringNotContainsString('[] !== self::$stack', $helper);
        $this->assertStringNotContainsString('use PHPCompiler\VM\ObStackLimits', $helper);
        $this->assertStringNotContainsString('ObStackLimits::BUF_SIZE', $helper);

        $writeKernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitObWriteStdoutKernel.php');
        $this->assertStringContainsString('lookupFunction(\'write\')', $writeKernel);
    }

    public function testSpineBundleIncludesObOutputWriteStdoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitObWriteStdoutKernel.php', $spine);
        $this->assertStringContainsString('phpc_ob_write_stdout_kernel.php', $spine);
        $this->assertStringContainsString('JitObOutputExecCaptureKernel.php', $spine);
        $this->assertStringContainsString('ObOutputJitHelper.php', $spine);
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
