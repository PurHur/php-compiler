<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for ??= (null coalescing assignment) JIT lowering (#3792, #1235).
 *
 * php-src: Zend/zend_compile.c (ZEND_ASSIGN_OP / IS_COALESCE), zend_execute.c
 *
 * MCJIT execute remains gated by jit-runtime-probe (#98); this test guards IR lowering.
 *
 * @group llvm
 */
final class CoalesceAssignJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — ??= JIT compile test needs LLVM (#3792)');
        }
    }

    public function testCoalesceAssignModuleVerify(): void
    {
        $runtime = new Runtime();
        foreach (
            [
                [$this->phptFixtureCode('coalesce_assign_jit.phpt'), 'coalesce_assign_jit.phpt'],
                [$this->phptFixtureCode('coalesce_assign_echo.phpt'), 'coalesce_assign_echo.phpt'],
                [
                    <<<'PHP'
<?php
$a = null;
$a ??= 5;
$b = 1;
$b ??= 9;
echo $a, ',', $b, "\n";
PHP,
                    'issue3792_repro.php',
                ],
                [
                    <<<'PHP'
<?php
$items = [];
$items['page'] ??= 'home';
echo $items['page'], "\n";
PHP,
                    'coalesce_assign_array_dim.php',
                ],
            ] as [$code, $filename]
        ) {
            $block = $runtime->parseAndCompile($code, $filename);
            $this->assertNotNull($block, $filename);
            $runtime->jitCompileBlock($block);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }
}
