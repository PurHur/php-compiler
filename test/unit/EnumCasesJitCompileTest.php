<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for Enum::cases() JIT lowering (#4068, #3308).
 *
 * php-src: Zend/zend_enum.c — zend_enum_list_cases
 *
 * @group llvm
 */
final class EnumCasesJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — Enum::cases() JIT compile test needs LLVM (#4068)');
        }
    }

    public function testEnumCasesModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
enum Suit: string { case Hearts = 'H'; case Spades = 'S'; }
$cases = Suit::cases();
echo count($cases), ':', $cases[0]->name, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString('suit::cases', $bc);
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
