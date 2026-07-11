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
