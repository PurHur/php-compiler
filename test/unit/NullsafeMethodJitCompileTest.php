<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * JIT compile nullsafe method calls on null-typed receivers (#4457).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class NullsafeMethodJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason . ' — nullsafe method JIT compile test needs LLVM');
        }
    }

    public function testNullTypedReceiverMethodCallCompiles(): void
    {
        $code = <<<'PHP'
<?php
class C { public function f($x) { return $x; } }
$c = null;
var_export($c?->f(1));
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nullsafe_method_literal.php');
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
