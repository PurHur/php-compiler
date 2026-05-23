<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class JitVmHotPathStubTest extends TestCase
{
    public function testRunFramesStubIsRegisteredWithoutCompilingSwitch(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $jit = $runtime->loadJit();
        $code = <<<'PHP'
<?php
namespace PHPCompiler;
class VmProbe {
    private function runFrames(): int {
        switch (1) { case 1: return 1; }
        return 2;
    }
}
PHP;
        $func = $runtime->compileFunc('PHPCompiler\\VmProbe::runFrames', $runtime->parse($code, 'probe.php')->functions[0]);
        $jit->compileFunc($func);
        $ctx = $runtime->loadJitContext();
        $proxy = $ctx->resolveFunctionProxy('phpcompiler\\vmprobe::runframes');
        $this->assertInstanceOf(JIT\Call\Native::class, $proxy);
    }

    public function testFileGetContentsConcatBootstrapAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/bootstrap-aot/file_get_contents_concat.php';
        $this->assertFileExists($target);
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim($stderr !== false ? $stderr : ''));
    }
}
