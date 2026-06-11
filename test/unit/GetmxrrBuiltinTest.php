<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\getmxrr;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM + JIT surface for getmxrr() (#3662). */
final class GetmxrrBuiltinTest extends TestCase
{
    public function testFunctionNameRegistered(): void
    {
        $fn = new getmxrr();
        $this->assertSame('getmxrr', $fn->getName());
    }

    public function testExampleComMxWhenResolverAvailable(): void
    {
        if (false === VmDns::dnsGetMx('example.com')) {
            $this->markTestSkipped('example.com MX records unavailable');
        }

        $runtime = new Runtime();
        $fn = new getmxrr();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('example.com');
        $mxVar = new VMVariable();
        $mxVar->array(new \PHPCompiler\VM\HashTable());
        $frame->calledArgs = [$hostVar, $mxVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
        $this->assertGreaterThan(0, $mxVar->resolveIndirect()->toArray()->getNumElements());
    }

    /**
     * @group llvm
     * @group aot-lint
     */
    public function testAotLintCompilesLiteralHostname(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $code = <<<'PHP'
$hosts = [];
$ok = getmxrr('example.com', $hosts);
echo $ok ? 'ok' : 'fail';
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_getmxrr_lint_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/bin/compile.php', '-l', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $stderr));
        @unlink($tmp);
    }
}
