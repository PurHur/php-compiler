<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT compile for (array)/(object) casts (#4887).
 *
 * @group llvm
 */
final class CastJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — cast JIT compile needs LLVM (#4887)');
        }
    }

    public function testArrayCastOnPackedListCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
echo count((array)[1, 2]);
PHP,
            'cast_array_packed.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testObjectCastFromLiteralArrayCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$o = (object)['x' => 1];
PHP,
            'cast_object_only.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testObjectThenArrayCastProbeCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
echo count((array)(object)['x' => 1]);
PHP,
            'cast_object_array.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testObjectCastOnEnumCaseCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
enum E: int { case A = 1; }
$o = (object) E::A;
echo $o === E::A ? "1\n" : "0\n";
PHP,
            'cast_object_enum_case.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
