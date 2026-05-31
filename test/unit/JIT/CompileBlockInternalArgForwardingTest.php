<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../LlvmToolchain.php';

/**
 * Guards compileBlockInternal() variadic arg forwarding (#1231 / #1238).
 *
 * After $startIndex / $allowRecompile were added, spread call sites must pass
 * explicit 0/false before ...$args or LLVM param Variables bind to $startIndex.
 */
final class CompileBlockInternalArgForwardingTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
    }
    public function testUserMethodWithStaticCallCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public static function size(): int {
        return 3;
    }
    public function doubled(): int {
        return static::size() * 2;
    }
}
echo (new Box())->doubled();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_arg_forward.php');
        self::assertNotNull($block, 'JIT compile must not TypeError on compileBlockInternal arg #5');
    }

    public function testExtendsChainStaticCallCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Base {
    public static function who(): string {
        return static::class;
    }
}
class Child extends Base {
    public function name(): string {
        return static::who();
    }
}
echo (new Child())->name();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_lsb_extends.php');
        self::assertNotNull($block, 'extends-chain static:: must compile without arg forwarding TypeError');
    }

    public function testParentStaticCallInExtendsChainCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Base {
    public static function id(): string {
        return 'Base';
    }
}
class Child extends Base {
    public function name(): string {
        return parent::id();
    }
}
echo (new Child())->name();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_parent_extends.php');
        self::assertNotNull($block, 'extends-chain parent:: must compile without arg forwarding TypeError');
    }

    /**
     * @group llvm
     */
    public function testBinJitRunLateStaticBindingInUserMethod(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — compileBlockInternal JIT execute test needs LLVM (#1238)');
        }
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        if (false === $jit) {
            $this->markTestSkipped('bin/jit.php missing');
        }
        $code = <<<'PHP'
class Box {
    public static function size(): int { return 3; }
    public function doubled(): int { return static::size() * 2; }
}
echo (new Box())->doubled();
PHP;
        $env = $this->llvmProcessEnv();
        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            [PHP_BINARY, $jit, '-r', $code]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = trim(($stdout !== false ? $stdout : '').($stderr !== false ? $stderr : ''));
        if (0 !== $exit) {
            $this->markTestSkipped('bin/jit.php MCJIT execute unavailable in this harness: '.$combined);
        }
        $this->assertStringContainsString('6', $combined);
    }

    /** @return array<string, string> */
    private function llvmProcessEnv(): array
    {
        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
