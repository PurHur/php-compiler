<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for lazy proxy/ghost objects (#4940).
 *
 * @group llvm
 */
final class LazyObjectJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        if (!CompilerVersion::supportsLazyObjectFactories()) {
            $this->markTestSkipped('Lazy object factories require stable PHP 8.4+ profile (#12375)');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — lazy object JIT execute needs LLVM (#4940)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4940)');
        }
    }

    public function testLazyProxyDefersConstructorViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn () => new Svc('x'));
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
PHP
            ,
            "before\ninit\nx\nx\n");
    }

    public function testLazyGhostDefersConstructorViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $object) {
    $object->__construct('x');
});
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
PHP
            ,
            "before\ninit\nx\nx\n");
    }

    /**
     * @covers issue #29171 — zend_lazy_object_clone initializes before clone
     *
     * Uses bin/jit.php: isUninitializedLazyObject is VM-lowered, so pure MCJIT
     * assertMcjitOutput cannot host this script (requiresVmLowering).
     */
    public function testCloneUninitializedLazyGhostInitializesViaJitCli(): void
    {
        $script = sys_get_temp_dir().'/phpc_lazy_ghost_clone_29171_'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
class C { public int $x = 1; }
$r = new ReflectionClass(C::class);
$g = $r->newLazyGhost(function (C $o) { $o->x = 42; echo "init\n"; });
echo "before_clone uninit=", $r->isUninitializedLazyObject($g) ? "yes" : "no", "\n";
$c = clone $g;
echo "after_clone g_uninit=", $r->isUninitializedLazyObject($g) ? "yes" : "no",
     " c_uninit=", $r->isUninitializedLazyObject($c) ? "yes" : "no", "\n";
echo "c.x=", $c->x, "\n";
echo "g.x=", $g->x, "\n";
PHP);
        $env = array_merge(getenv(), [
            'PHP_COMPILER_PROFILE' => '8.4',
        ]);
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/jit.php', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($script);
        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "before_clone uninit=yes\ninit\nafter_clone g_uninit=no c_uninit=no\nc.x=42\ng.x=42\n",
            $stdout
        );
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $this->assertTrue(Block::containsLazyObjectOpcodes($block));
        $runtime->jit($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
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
