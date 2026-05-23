<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPUnit\Framework\TestCase;

/**
 * @group aot-lint
 */
final class JitExternalMethodStubTest extends TestCase
{
    public function testExternalMethodProxyIsRegisteredOnDemand(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $proxy = $ctx->resolveFunctionProxy('vendorlike::addvisitor');
        $this->assertInstanceOf(ExternalMethod::class, $proxy);
        $this->assertSame('vendorlike::addvisitor', $proxy->proxyName);
    }

    public function testNamespacedCallFallsBackToGlobalBuiltin(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new JIT\Context($runtime, JIT\Builtin::LOAD_TYPE_STANDALONE);
        $global = $ctx->resolveFunctionProxy('dirname');
        $namespaced = $ctx->resolveFunctionProxy('phpcompiler\\web\\dirname');
        $this->assertSame($global, $namespaced);
    }

    public function testExternalMethodStubBootstrapAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/bootstrap-aot/external_method_stub.php';
        $this->assertFileExists($target);

        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for external_method_stub.php'
        );
    }
}
