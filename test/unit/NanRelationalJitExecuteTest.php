<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * NaN/INF relational JIT: LLVM verify + bin/jit.php file execute (#5084).
 *
 * @group llvm
 */
final class NanRelationalJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — NaN relational JIT needs LLVM (#5084)');
        }
    }

    public function testNativeDoubleNanCompareModuleVerify(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class NanRelationalJitProbe {
    public static function run(): void {
        $nan = NAN;
        echo ($nan < $nan) ? 'lt' : 'nlt', ' ';
        echo ($nan == $nan) ? 'eq' : 'neq', ' ';
        echo ($nan <=> $nan), "\n";
    }
}
NanRelationalJitProbe::run();
PHP;
        $block = $runtime->parseAndCompile($code, 'nan_relational_jit_probe.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    public function testBinJitFilePathRunsClasslessScript(): void
    {
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — bin/jit.php execute unavailable (#5084)');
        }
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $tmp = tempnam(sys_get_temp_dir(), 'nan_jit_');
        $this->assertNotFalse($tmp);
        $script = $tmp.'.php';
        rename($tmp, $script);
        file_put_contents($script, "<?php\n".<<<'PHP'
$nan = acos(2);
echo ($nan < $nan) ? 'lt' : 'nlt', ' ';
echo ($nan == $nan) ? 'eq' : 'neq', ' ';
echo ($nan <=> $nan), "\n";
PHP);
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($jit).' '.escapeshellarg($script))
        );
        exec($cmd, $out, $code);
        @unlink($script);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame("nlt neq 1\n", implode("\n", $out)."\n");
    }

    private function jitRuntimeProbeGreen(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe))
        );
        exec($cmd, $out, $code);

        return 0 === $code && str_contains(implode("\n", $out), 'jit-runtime-probe OK');
    }
}
