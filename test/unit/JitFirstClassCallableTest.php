<?php

declare(strict_types=1);

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 * @group jit
 */
final class JitFirstClassCallableTest extends TestCase
{
    public function testFunctionBuiltinCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
$fn = strlen(...);
echo $fn('abc');
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Variable function calls not yet supported', $stderr);
        self::assertStringNotContainsString('Call to undefined function', $stderr);
    }

    public function testStaticMethodCallableCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
class C {
    public static function id() { return 'ok'; }
}
$fn = C::id(...);
echo $fn();
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Variable function calls not yet supported', $stderr);
        self::assertStringNotContainsString('Call to undefined static method', $stderr);
    }

    public function testInstanceMethodCallableCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
class Greeter {
    public function greet() { return 'hello'; }
    public function add(int $a, int $b): int { return $a + $b; }
}
$obj = new Greeter();
$call = $obj->greet(...);
echo $call(), "\n";
$add = $obj->add(...);
echo $add(2, 3), "\n";
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Unknown array write op', $stderr);
        self::assertStringNotContainsString('Module verification failed', $stderr);
        self::assertStringNotContainsString('Variable function calls not yet supported', $stderr);
    }

    /** Issue #10168: (new MC())->m(...) instance-method FCC must JIT-compile. */
    public function testInstanceMethodCallableOnNewExpressionCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
class MC { public function m(): int { return 7; } }
$c = (new MC())->m(...);
echo $c(), "\n";
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Call to undefined method PHPTypes\\Type::array()', $stderr);
        self::assertStringNotContainsString('Module verification failed', $stderr);
        self::assertStringNotContainsString('Variable function calls not yet supported', $stderr);
    }

    /** Issue #6845 / #9250: enum case E::A->f(...) FCC must JIT-compile (empty userType + classHint). */
    public function testEnumCaseMethodCallableCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    public function f(): int { return 42; }
}
$c = E::A->f(...);
echo $c(), "\n";
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Call to undefined method', $stderr);
        self::assertStringNotContainsString('Module verification failed', $stderr);
    }

    /** Issue #9605: invokable object (new C)(...) FCC must JIT-compile. */
    public function testInvokableObjectCallableCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
class C {
    public function __invoke(): void {
        echo "ok\n";
    }
}
$fn = (new C)(...);
$fn();
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('TYPE_FROM_CALLABLE: unsupported callable form', $stderr);
        self::assertStringNotContainsString('Module verification failed', $stderr);
    }

    public function testCompilerLowersFirstClassCallableToFromCallableOpcode(): void
    {
        $code = <<<'PHP'
<?php
$fn = strlen(...);
$fn('x');
PHP;
        $rt = new PHPCompiler\Runtime();
        $block = $rt->parseAndCompile($code, 'fcc_fold.php');
        $foundFromCallable = false;
        foreach ($block->opCodes as $op) {
            if (PHPCompiler\OpCode::TYPE_FROM_CALLABLE === $op->type) {
                $foundFromCallable = true;
            }
        }
        self::assertTrue($foundFromCallable, 'expected TYPE_FROM_CALLABLE for strlen(...)');
    }

    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason()
                ?? 'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private function runJitProbeInSubprocess(string $code): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = tempnam(sys_get_temp_dir(), 'jit_fcc_src_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'jit_fcc_probe_');
        $this->assertNotFalse($probePath);
        $probePhp = $probePath.'.php';
        rename($probePath, $probePhp);
        file_put_contents($probePhp, <<<'PROBE'
<?php
require 'test/bootstrap.php';
PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
$source = $argv[1];
$code = file_get_contents($source);
$runtime = new PHPCompiler\Runtime();
$block = $runtime->parseAndCompile($code, basename($source));
try {
    $runtime->jit($block);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PROBE
        );

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $argv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($argv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return $stderr;
    }
}
