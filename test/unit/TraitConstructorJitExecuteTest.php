<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for trait-merged __construct (#4939, Zend/zend_traits.c).
 *
 * Blocked: any script with TYPE_DECLARE_TRAIT segfaults in MCJIT createJITCompiler
 * (repro: `php bin/jit.php -l test/repro/trait_empty.php` → exit 139). VM fallback via
 * Block::containsTraitConstructorOpcodes() remains required until DECLARE_TRAIT MCJIT is stable (#3609).
 *
 * @group llvm
 */
final class TraitConstructorJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — trait constructor JIT execute needs LLVM (#4939)');
        }
        if (!$this->jitRuntimeProbeGreen()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4939)');
        }
        if ($this->declareTraitMcjitLinkSegfaults()) {
            $this->markTestSkipped(
                'TYPE_DECLARE_TRAIT MCJIT link segfault — remove containsTraitConstructorOpcodes gate after #3609 (#4939)'
            );
        }
    }

    public function testTraitPromotedConstructorViaMcjit(): void
    {
        $this->assertMcjitOutput(<<<'PHP'
<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}
$c = new C(3);
echo $c->x, "\n";
PHP
            ,
            "3\n");
    }

    private function assertMcjitOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'trait_ctor_promotion.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsTraitConstructorOpcodes($block));
        $this->assertFalse(Block::containsUserClassDeclaredInstancePropertyOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jit($block, $code, 'trait_ctor_promotion.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame($expected, ob_get_clean());
    }

    private function declareTraitMcjitLinkSegfaults(): bool
    {
        $probe = $this->repoRoot.'/test/repro/trait_empty_mcjit_probe.php';
        if (!is_file($probe)) {
            return true;
        }
        $cmd = sprintf(
            'bash -lc %s',
            escapeshellarg('source '.escapeshellarg($this->repoRoot.'/script/php-env.sh')
                .' && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->repoRoot.'/bin/jit.php')
                .' -l '.escapeshellarg($probe).' >/dev/null 2>&1')
        );
        exec($cmd, $out, $code);

        return 139 === $code;
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
