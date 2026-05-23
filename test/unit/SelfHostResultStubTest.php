<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class SelfHostResultStubTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT');
    }

    public function testResultGetCallableIsJitStubbedWhenSelfHostAotEnabled(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $runtime = new Runtime(Runtime::MODE_AOT);
        $jit = $runtime->loadJit();
        $code = <<<'PHP'
<?php
namespace PHPCompiler\JIT;
class Result {
    public function getCallable(string $funcName, string $callbackType): callable {
        return [self::class, 'noop'];
    }
    public static function noop(): void {
    }
}
PHP;
        $cfgFunc = $runtime->parse($code, 'probe.php')->functions[0];
        $func = $runtime->compileFunc('PHPCompiler\\JIT\\Result::getCallable', $cfgFunc);
        $jit->compileFunc($func);
        $ctx = $runtime->loadJitContext();
        $proxy = $ctx->resolveFunctionProxy('phpcompiler\\jit\\result::getcallable');
        $this->assertInstanceOf(JIT\Call\Native::class, $proxy);
    }

    public function testRealResultGetCallableReturnsNoopUnderSelfHostAot(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $engine = $this->createMock(\PHPLLVM\ExecutionEngine::class);
        $result = new JIT\Result($engine, JIT\Builtin::LOAD_TYPE_IMPORT);
        $callable = $result->getCallable('__init__', 'void(*)()');
        $this->assertSame([JIT\Result::class, 'selfHostNoopHandler'], $callable);
        $callable();
        $this->assertTrue(true);
    }

    public function testJitResultStubBootstrapAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/bootstrap-aot/jit_result_stub.php';
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
