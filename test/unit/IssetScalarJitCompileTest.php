<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for isset() scalar + unsupported offset lowering (#4081).
 *
 * @group llvm
 */
final class IssetScalarJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — isset scalar JIT compile test needs LLVM (#4081)');
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIssetScalarLocalCompilesWithoutLogicException(): void
    {
        $this->assertIssetSnippetCompiles(<<<'PHP'
<?php
$x = 1;
if (isset($x)) {
    echo "yes\n";
} else {
    echo "no\n";
}
PHP
            ,
            'int_local'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIssetUnsupportedIntOffsetCompilesWithoutLogicException(): void
    {
        $this->assertIssetSnippetCompiles(<<<'PHP'
<?php
$x = 1;
if (isset($x[0])) {
    echo "yes\n";
} else {
    echo "no\n";
}
PHP
            ,
            'int_offset'
        );
    }

    private function assertIssetSnippetCompiles(string $code, string $label): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'isset_scalar_jit_'.$label.'.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block), $label);
        try {
            $runtime->jitCompileBlock($block);
        } catch (\LogicException $e) {
            $this->fail($label.': isset() must not throw LogicException: '.$e->getMessage());
        }
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
